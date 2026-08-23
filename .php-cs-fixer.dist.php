<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config'])
    // Auto-generated Symfony config-reference dump (gitignored, regenerated) —
    // "for apps only", not our code; php-cs-fixer's finder ignores .gitignore.
    ->notPath('reference.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // Keep inline `/** @var … */` hints (before return/assignment) as docblocks —
        // phpstan reads them; the default rule would downgrade them to `/* … */`.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'header_comment' => ['header' => <<<'EOF'
This file is part of the UhifadhiLabs Patrol Module.

(c) Ezekiel Mjema <https://github.com/eemjema>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF],
    ])
    ->setFinder($finder);
