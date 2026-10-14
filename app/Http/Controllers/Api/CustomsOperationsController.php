<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IpsOperationException;
use App\Http\Controllers\Controller;
use App\Services\Postal\CustomsApiPresenter;
use App\Services\Postal\CustomsOperationsService;
use App\Services\Postal\PostalWorkspace;
use App\Services\Postal\ShipmentCode;
use App\Services\Postal\UpuDispatchIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CustomsOperationsController extends Controller
{
    public function lookup(string $codigo, CustomsOperationsService $customs, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $customs, $workspace, $presenter) {
            $codigo = ShipmentCode::normalize($codigo);
            $analysis = UpuDispatchIdentifier::parse($codigo);

            if (($analysis['type'] ?? null) === 's8') {
                $result = $customs->inspectDispatch($codigo);
                if (empty($result['dispatches'])) {
                    throw new IpsOperationException('No se encontró el despacho S8 en IPS.', 404);
                }

                return ['data' => $presenter->general('S8', $result)];
            }

            if (($analysis['type'] ?? null) === 's9') {
                $result = $customs->inspectReceptacle($codigo);

                return ['data' => $presenter->general('S9', $result)];
            }

            $result = $workspace->search($codigo, true, true);
            $sources = collect($result['sources'] ?? []);
            if (!$sources->contains('ok')) {
                if ($sources->contains('unavailable')) {
                    throw new IpsOperationException('IPS o CDS no está disponible; la consulta general quedó incompleta.', 503);
                }
                throw new IpsOperationException('No se encontró el paquete o identificador en IPS/CDS.', 404);
            }

            $type = ShipmentCode::describe($codigo)['type'] === 'S10' ? 'S10' : 'Código local';

            return ['data' => $presenter->general($type, $result)];
        });
    }

    public function package(string $codigo, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $workspace, $presenter) {
            $result = $workspace->search($codigo, true, true);
            $this->assertWorkspaceFound($result);

            return ['data' => $presenter->package($result)];
        });
    }

    public function declarations(string $codigo, PostalWorkspace $workspace, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $workspace, $presenter) {
            $result = $workspace->search($codigo, true, true);
            $this->assertWorkspaceFound($result);

            return ['data' => $presenter->declarations($result)];
        });
    }

    public function dispatch(string $codigo, CustomsOperationsService $customs, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(function () use ($codigo, $customs, $presenter) {
            $result = $customs->inspectDispatch($codigo);
            if (empty($result['dispatches'])) {
                throw new IpsOperationException('No se encontro el despacho S8 en IPS.', 404);
            }

            return ['data' => $presenter->dispatch($result)];
        });
    }

    public function receptacle(string $codigo, CustomsOperationsService $customs, CustomsApiPresenter $presenter): JsonResponse
    {
        return $this->respond(fn () => ['data'=>$presenter->receptacle(
            $customs->inspectReceptacle($codigo)
        )]);
    }

    private function assertWorkspaceFound(array $result): void
    {
        $sources = collect($result['sources'] ?? []);
        if ($sources->contains('ok')) {
            return;
        }

        if ($sources->contains('unavailable')) {
            throw new IpsOperationException('IPS o CDS no esta disponible; la consulta quedo incompleta.', 503);
        }

        throw new IpsOperationException('No se encontro el paquete o identificador en IPS/CDS.', 404);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (IpsOperationException $exception) {
            return response()->json(['message'=>$exception->getMessage()], $exception->status)->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            Log::warning('Customs API query unavailable', ['exception_type'=>$exception::class]);
            return response()->json(['message'=>'No se pudo completar la consulta aduanera.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
