<?php

declare(strict_types=1);

namespace Thallo\Search\Render;

use Thallo\Render\Contribution\TemplatePathContributor;

/** The search pack's templates: the Search block and the `/search` page. */
final class SearchTemplatePathContributor implements TemplatePathContributor
{
    public function contributorId(): string
    {
        return 'thallo-search.templates';
    }

    public function priority(): int
    {
        return 0;
    }

    public function templatePaths(): array
    {
        return [dirname(__DIR__, 2) . '/templates'];
    }
}
