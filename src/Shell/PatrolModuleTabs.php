<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Patrol\Shell;

use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;

/**
 * WHERE PATROL DATA LIVES — the two places, and the whole of what this module
 * says about its own navigation.
 *
 * A TAB IS A PLACE WHERE DATA LIVES. The dashboard reads the month; the full
 * log is every patrol in it, uncapped; the observation kinds are the words this
 * area files under and what has been filed against each, read-only. Nothing
 * that CONFIGURES the module is here — writing those words, the widget library
 * and the module's settings are sections of the one configure page, reached
 * from the one Configure action the shell draws.
 *
 * THE SHELL DRAWS BOTH RENDERINGS. The strip under the page head and the
 * module's children in the sidebar's tree come from this one list, so the two
 * cannot drift the way two hand-kept copies eventually would — which is why
 * this module ships no tab partial of its own.
 */
final readonly class PatrolModuleTabs implements ModuleTabsInterface
{
    public function slug(): string
    {
        return PatrolModuleProvider::SLUG;
    }

    public function tabs(): array
    {
        return [
            new ModuleTab('Overview', 'patrol_dashboard'),
            /*
             * A LIST AND ITS DETAIL SCREENS ARE ONE PLACE. Opening a patrol —
             * and, from there, one of its observations — does not leave the
             * place patrols live in, so the family is named here rather than
             * guessed from a url shape by anybody else.
             */
            new ModuleTab('Patrols', 'patrol_list', lightsFor: [
                'patrol_list',
                'patrol_show',
                'patrol_observation_show',
            ]),
            /*
             * THE WORDS ARE DATA, so the counts against them are a place and not
             * a setting. Writing them is a Configure section and stays one.
             */
            new ModuleTab('Observation kinds', 'patrol_kinds_overview'),
        ];
    }
}
