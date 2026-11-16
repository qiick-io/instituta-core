<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\HttpBrainContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * The resolved view of one product as an attachable resource (#202), the same content as the
 * instituta_resolve tool and `GET /products/{id}/resolved`.
 */
#[Name('resolved-product')]
#[MimeType('application/json')]
#[Description('The resolved brain view for one product: the most specific entry per key (product over company over organisation).')]
class InstitutaResolvedProductResource extends Resource implements HasUriTemplate
{
    public function __construct(private readonly HttpBrainContext $brain = new HttpBrainContext) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('instituta://products/{product_id}/resolved');
    }

    public function handle(Request $request): Response
    {
        $resolved = $this->brain->contract()->resolve(
            $this->brain->perimeter(),
            $this->brain->apiKey(),
            (string) $request->get('product_id'),
            null,
        );

        return Response::text(json_encode($resolved, JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
