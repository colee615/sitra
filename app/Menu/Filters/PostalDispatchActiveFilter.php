<?php

namespace App\Menu\Filters;

use App\Services\Postal\UpuDispatchIdentifier;
use JeroenNoten\LaravelAdminLte\Menu\Filters\FilterInterface;

class PostalDispatchActiveFilter implements FilterInterface
{
    public function transform($item)
    {
        $identifier = (string) request()->query('marbete', '');
        $analysis = UpuDispatchIdentifier::parse($identifier);

        if (($analysis['type'] ?? null) !== 's8') {
            return $item;
        }

        if (($item['text'] ?? null) === 'Despachos (S8)') {
            $item['active'] = true;
        } elseif (($item['text'] ?? null) === 'Sacas y marbetes (S9)') {
            $item['active'] = false;
        }

        return $item;
    }
}
