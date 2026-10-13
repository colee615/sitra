<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IpsOperationException;
use App\Http\Controllers\Controller;
use App\Services\Postal\CustomsOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CustomsOperationsController extends Controller
{
    public function dispatch(string $codigo, CustomsOperationsService $customs): JsonResponse
    {
        return $this->respond(fn () => ['data'=>$customs->inspectDispatch($codigo)]);
    }

    public function receptacle(Request $request, string $codigo, CustomsOperationsService $customs): JsonResponse
    {
        $filters = $request->validate(['office_cd'=>['nullable','integer','min:1','max:32767']]);
        return $this->respond(fn () => ['data'=>$customs->inspectReceptacle($codigo, isset($filters['office_cd']) ? (int) $filters['office_cd'] : null)]);
    }

    public function receptacleEvent(Request $request, string $codigo, CustomsOperationsService $customs): JsonResponse
    {
        $data = $request->validate([
            'event'=>['required','in:RECEPTACLE_RECEIVE,RECEPTACLE_OPEN'],
            'office_cd'=>['required','integer','min:1','max:32767'],
            'physical_receipt_confirmed'=>['required','accepted'],
        ]);
        $key = $request->header('Idempotency-Key');
        validator(['key'=>$key], ['key'=>['required','string','min:8','max:128','regex:/^[A-Za-z0-9_.:-]+$/']])->validate();

        return $this->respond(function () use ($customs, $codigo, $data, $request, $key) {
            $operation = $customs->recordReceptacleEvent(
                $codigo, $data['event'], (int) $data['office_cd'], (int) $request->user()->id, $key, 'customs_api'
            );
            return ['status'=>$operation['body']['status'] ?? 'uncertain', 'result'=>$operation['body'] ?? [],
                'replayed'=>(bool) ($operation['replayed'] ?? false)];
        }, true);
    }

    public function openSession(Request $request, CustomsOperationsService $customs): JsonResponse
    {
        $data = $request->validate([
            'codigo'=>['required','string','max:35'], 'office_cd'=>['required','integer','min:1','max:32767'],
        ]);
        return $this->respond(fn () => ['data'=>(array) $customs->openSession(
            $data['codigo'], (int) $data['office_cd'], (int) $request->user()->id, 'customs_api'
        )]);
    }

    public function scan(Request $request, string $session, CustomsOperationsService $customs): JsonResponse
    {
        $data = $request->validate(['codigo'=>['required','string','max:35']]);
        return $this->respond(fn () => ['data'=>$customs->recordPhysicalScan(
            $session, $data['codigo'], (int) $request->user()->id
        )]);
    }

    public function packageAction(Request $request, string $session, string $codigo, string $action, CustomsOperationsService $customs): JsonResponse
    {
        $request->merge(['action'=>$action]);
        $data = $request->validate([
            'action'=>['required','in:present,decision,return'],
            'customs_office_cd'=>['required_if:action,present','nullable','integer','min:1','max:32767'],
            'customs_office_type_cd'=>['required_if:action,present','nullable','string','max:3','regex:/^[A-Za-z0-9]+$/'],
            'customs_release_status_cd'=>['required_if:action,decision','nullable','string','max:3','regex:/^[A-Za-z0-9]+$/'],
            'notes'=>['nullable','string','max:2000'], 'evidence_reference'=>['nullable','string','max:100'],
            'physical_receipt_confirmed'=>[Rule::excludeIf($action !== 'return'),'required','accepted'],
        ]);
        $key = $request->header('Idempotency-Key');
        validator(['key'=>$key], ['key'=>['required','string','min:8','max:128','regex:/^[A-Za-z0-9_.:-]+$/']])->validate();
        $data['idempotency_key'] = $key;

        return $this->respond(fn () => $customs->recordCaseAction(
            $session, $codigo, $action, $data, (int) $request->user()->id, 'customs_api'
        ), true);
    }

    private function respond(callable $callback, bool $operation = false): JsonResponse
    {
        try {
            $result = $callback();
            $status = $operation ? (int) ($result['status'] === 'succeeded' ? 200 : ($result['status'] === 'uncertain' ? 503 : 409)) : 200;
            $response = response()->json($result, $status)->header('Cache-Control', 'no-store');
            if ($operation) $response->header('Idempotency-Replayed', !empty($result['replayed']) ? 'true' : 'false');
            return $response;
        } catch (IpsOperationException $exception) {
            return response()->json(['message'=>$exception->getMessage()], $exception->status)->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            Log::warning('Customs API operation unavailable', ['exception_type'=>$exception::class]);
            return response()->json(['message'=>'No se pudo completar la operación. Consulta su estado antes de reenviar.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
