<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name_lt' => 'MĖSA IR DEŠRA', 'slug' => 'mesa-ir-desra'],
            ['name_lt' => 'VAISIAI IR DARŽOVĖS', 'slug' => 'vaisiai-ir-darzoves'],
            ['name_lt' => 'ŠVIEŽI MAISTO PRODUKTAI', 'slug' => 'sviezi-maisto-produktai'],
            ['name_lt' => 'KRUOPOS IR MAKARONŲ GAMINIAI', 'slug' => 'kruopos-ir-makaronu-gaminiai'],
            ['name_lt' => 'KONSERVAI', 'slug' => 'konservai'],
            ['name_lt' => 'GĖRIMAI', 'slug' => 'gerimai'],
            ['name_lt' => 'ILGAI NEGENDANTYS KEPINIAI IR SALDUMYNAI', 'slug' => 'ilgai-negendantys-kepiniai-ir-saldumynai'],
            ['name_lt' => 'SKALBIMO PRIEMONĖS', 'slug' => 'skalbimo-priemones'],
            ['name_lt' => 'PARFUMERIJOS GAMINIAI', 'slug' => 'parfumerijos-gaminiai'],
            ['name_lt' => 'NE MAISTO PREKĖS', 'slug' => 'ne-maisto-prekes'],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->insertOrIgnore($category);
        }

        $subcategories = [
            'MĖSA IR DEŠRA' => [
                ['name_lt' => 'PAKAVIMO MEDŽIAGA / PRIESKONIAI', 'slug' => 'pakavimo-medziaga-prieskoniai'],
                ['name_lt' => 'KITOS RŪŠIES DEŠRA', 'slug' => 'kitos-rusies-desra'],
                ['name_lt' => 'PAUKŠTIENA', 'slug' => 'paukstiena'],
                ['name_lt' => 'KIAULIENA', 'slug' => 'kiauliena'],
                ['name_lt' => 'JAUTIENA', 'slug' => 'jautiena'],
                ['name_lt' => 'VERŠIENA', 'slug' => 'versiena'],
                ['name_lt' => 'ĖRIENA', 'slug' => 'eriena'],
                ['name_lt' => 'ŽVĖRIENA IR TRIUŠIENA', 'slug' => 'zveriena-ir-triusiena'],
                ['name_lt' => 'ŽUVIS', 'slug' => 'zuvis'],
                ['name_lt' => 'GAMYBA', 'slug' => 'gamyba'],
            ],
            'VAISIAI IR DARŽOVĖS' => [
                ['name_lt' => 'VAISIAI', 'slug' => 'vaisiai'],
                ['name_lt' => 'DARŽOVĖS', 'slug' => 'darzoves'],
                ['name_lt' => 'VAISIŲ IR DARŽOVIŲ PUSGAMINIAI', 'slug' => 'vaisiu-ir-darzoviu-pusgaminiai'],
                ['name_lt' => 'AUGALAI', 'slug' => 'augalai'],
            ],
            'ŠVIEŽI MAISTO PRODUKTAI' => [
                ['name_lt' => 'PIENO PRODUKTAI', 'slug' => 'pieno-produktai'],
                ['name_lt' => 'ILGAI NEGENDANTYS MAISTO PRODUKTAI', 'slug' => 'ilgai-negendantys-maisto-produktai'],
                ['name_lt' => 'SŪRIS', 'slug' => 'suris'],
                ['name_lt' => 'DELIKATESAI', 'slug' => 'delikatesai'],
                ['name_lt' => 'ŠALDYTI PRODUKTAI', 'slug' => 'saldyti-produktai'],
                ['name_lt' => 'KIAUŠINIAI', 'slug' => 'kiausiniai'],
                ['name_lt' => 'ĮVAIRŪS ŠVIEŽI MAISTO PRODUKTAI', 'slug' => 'ivairus-sviezi-maisto-produktai'],
                ['name_lt' => 'DUONA IR KEPINIAI', 'slug' => 'duona-ir-kepiniai'],
            ],
            'KRUOPOS IR MAKARONŲ GAMINIAI' => [
                ['name_lt' => 'BŪTINIEJI MAISTO PRODUKTAI', 'slug' => 'butinieji-maisto-produktai'],
                ['name_lt' => 'ACTAS / ALIEJUS / RIEBALAI', 'slug' => 'actas-aliejus-riebalai'],
                ['name_lt' => 'SINTETINIAI SALDIKLIAI', 'slug' => 'sintetiniai-saldikliai'],
                ['name_lt' => 'SKONINĖS MEDŽIAGOS', 'slug' => 'skonines-medziagos'],
                ['name_lt' => 'SRIUBOS / PADAŽAI', 'slug' => 'sriubos-padazai'],
                ['name_lt' => 'SVEIKAS MAISTAS / DIETINIAI PRODUKTAI', 'slug' => 'sveikas-maistas-dietiniai-produktai'],
                ['name_lt' => 'KEPIMO PRIEMONĖS / RIEŠUTAI', 'slug' => 'kepimo-priemones-riesutai'],
                ['name_lt' => 'PUDINGO / DESERTO MILTELIAI', 'slug' => 'pudingo-deserto-milteliai'],
            ],
            'KONSERVAI' => [
                ['name_lt' => 'KONSERVUOTOS DARŽOVĖS IR VAISIAI', 'slug' => 'konservuotos-darzoves-ir-vaisiai'],
                ['name_lt' => 'DŽEMAI / ŠOKOLADINIAI KREMAI', 'slug' => 'dzemai-sokoladiniai-kremai'],
                ['name_lt' => 'MĖSOS KONSERVAI / UŽTEPAI', 'slug' => 'mesos-konservai-uztepai'],
                ['name_lt' => 'ŽUVIES KONSERVAI / JŪROS GĖRYBĖS', 'slug' => 'zuvies-konservai-juros-gerybes'],
                ['name_lt' => 'GYVŪNŲ MAISTAS / PRIEŽIŪROS REIKMENYS', 'slug' => 'gyvunu-maistas-prieziuros-reikmenys'],
                ['name_lt' => 'KŪDIKIŲ MAISTAS', 'slug' => 'kudikiu-maistas'],
                ['name_lt' => 'ETNINIS MAISTAS', 'slug' => 'etninis-maistas'],
            ],
            'GĖRIMAI' => [
                ['name_lt' => 'KAVA', 'slug' => 'kava'],
                ['name_lt' => 'ARBATA / KAKAVA', 'slug' => 'arbata-kakava'],
                ['name_lt' => 'NEALKOHOLINIAI GĖRIMAI', 'slug' => 'nealkoholiniai-gerimai'],
                ['name_lt' => 'ALUS', 'slug' => 'alus'],
                ['name_lt' => 'ALKOHOLINIAI GĖRIMAI', 'slug' => 'alkoholiniai-gerimai'],
                ['name_lt' => 'VYNAS / ŠAMPANIZUOTAS VYNAS', 'slug' => 'vynas-sampanizuotas-vynas'],
            ],
            'ILGAI NEGENDANTYS KEPINIAI IR SALDUMYNAI' => [
                ['name_lt' => 'LENGVI UŽKANDŽIAI', 'slug' => 'lengvi-uzkandziai'],
                ['name_lt' => 'ILGAI NEGENDANTYS KEPINIAI IR SAUSAINIAI', 'slug' => 'ilgai-negendantys-kepiniai-ir-sausainiai'],
                ['name_lt' => 'KRAMTOMOJI GUMA', 'slug' => 'kramtomoji-guma'],
                ['name_lt' => 'ŠOKOLADAS', 'slug' => 'sokoladas'],
                ['name_lt' => 'SALDUMYNAI', 'slug' => 'saldumynai'],
                ['name_lt' => 'SEZONINIAI MAISTO PRODUKTAI', 'slug' => 'sezoniniai-maisto-produktai'],
            ],
            'SKALBIMO PRIEMONĖS' => [
                ['name_lt' => 'SKALBIMO PRIEMONĖS / PRIEDAI', 'slug' => 'skalbimo-priemones-priedai'],
                ['name_lt' => 'INDŲ PLOVIKLIAI / VALYMO PRIEMONĖS', 'slug' => 'indu-plovikliai-valymo-priemones'],
                ['name_lt' => 'SANITARINĖS PRIEMONĖS', 'slug' => 'sanitarines-priemones'],
                ['name_lt' => 'BATŲ PRIEŽIŪROS PRIEMONĖS / REIKMENYS', 'slug' => 'batu-prieziuros-priemones-reikmenys'],
            ],
            'PARFUMERIJOS GAMINIAI' => [
                ['name_lt' => 'PLAUKŲ PRIEŽIŪROS PRIEMONĖS', 'slug' => 'plauku-prieziuros-priemones'],
                ['name_lt' => 'DANTŲ / PĖDŲ PRIEŽIŪROS PRIEMONĖS', 'slug' => 'dantu-pedu-prieziuros-priemones'],
                ['name_lt' => 'KOSMETIKA', 'slug' => 'kosmetika'],
                ['name_lt' => 'MUILAS / VONIOS IR DUŠO ŽELĖ', 'slug' => 'muilas-vonios-ir-duso-zele'],
                ['name_lt' => 'KŪNO PRIEŽIŪROS PRIEMONĖS / APSAUGA', 'slug' => 'kuno-prieziuros-priemones-apsauga'],
                ['name_lt' => 'POPIERIAUS GAMINIAI', 'slug' => 'popieriaus-gaminiai'],
            ],
            'NE MAISTO PREKĖS' => [
                ['name_lt' => 'TEKSTILĖS GAMINIAI / DRABUŽIAI', 'slug' => 'tekstiles-gaminiai-drabuziai'],
                ['name_lt' => 'VIRTUVĖS REIKMENYS', 'slug' => 'virtuves-reikmenys'],
                ['name_lt' => 'NAMŲ APYVOKOS REIKMENYS', 'slug' => 'namu-apyvokos-reikmenys'],
                ['name_lt' => 'ŽAIDIMAI / KNYGOS / MUZIKA', 'slug' => 'zaidimai-knygos-muzika'],
                ['name_lt' => 'SODO / TABAKO GAMINIAI', 'slug' => 'sodo-tabako-gaminiai'],
                ['name_lt' => 'SEZONINĖS / MOKYKLINĖS / KANCELIARINĖS PREKĖS', 'slug' => 'sezonines-mokyklines-kanceliarines-prekes'],
                ['name_lt' => 'KREPŠELIAI SU RANKENOMIS / KREPŠIAI', 'slug' => 'krepseliai-su-rankenomis-krepsiai'],
                ['name_lt' => 'PAKUOTĖS MEDŽIAGA ĮSKAITYTA', 'slug' => 'pakuotes-medziaga-iskaityta'],
                ['name_lt' => 'FILIALO / PREKYBOS CENTRO KAŠTAI', 'slug' => 'filialo-prekybos-centro-kastai'],
                ['name_lt' => 'PAKAVIMO MEDŽIAGOS / SKIRTUMAI', 'slug' => 'pakavimo-medziagos-skirtumai'],
            ],
        ];

        foreach ($subcategories as $categoryName => $subs) {
            $categoryId = DB::table('categories')->where('name_lt', $categoryName)->value('id');

            foreach ($subs as $sub) {
                DB::table('subcategories')->insertOrIgnore(array_merge($sub, ['category_id' => $categoryId]));
            }
        }
    }
}
