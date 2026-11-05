<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\GeographyImport;
use App\Models\Province;
use App\Models\State;
use Composer\InstalledVersions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Intl\Countries;

class GeographySeeder extends Seeder
{
    private const NIGERIA_SOURCE_KEY = 'aetechlabs/nigeria-states-lgas';

    private const NIGERIA_SOURCE_VERSION = '9382dd39696e647f72e026e575ec836e1b9a92b9';

    public function run(): void
    {
        DB::transaction(function (): void {
            $countryImport = $this->recordImport(
                'symfony/intl-country-names',
                'https://github.com/symfony/intl',
                InstalledVersions::getPrettyVersion('symfony/intl') ?? 'unknown',
                'Symfony Intl package; upstream locale data notices apply',
                ['dataset' => 'ISO 3166-1 alpha-2 country codes with CLDR display names'],
            );
            $nigeriaImport = $this->recordImport(
                self::NIGERIA_SOURCE_KEY,
                'https://github.com/aetechlabs/nigeria-states-lgas/blob/9382dd39696e647f72e026e575ec836e1b9a92b9/data/nigeria_lgas.csv',
                self::NIGERIA_SOURCE_VERSION,
                'MIT',
                ['dataset' => 'Nigeria states and local government areas', 'expected_province_count' => 774],
            );

            $countryCount = 0;
            foreach (Countries::getNames('en') as $code => $name) {
                if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
                    continue;
                }

                $country = Country::query()->firstOrNew(['code' => $code]);
                $country->name = $name;
                $country->source_import_id = $countryImport->id;
                $country->is_active ??= true;
                if ($code === 'NG') {
                    $country->currency_code = 'NGN';
                    $country->default_timezone = 'Africa/Lagos';
                }
                $country->save();
                $countryCount++;
            }

            $countryImport->update(['metadata' => [
                'dataset' => 'ISO 3166-1 alpha-2 country codes with CLDR display names',
                'record_count' => $countryCount,
            ]]);

            $path = database_path('seeders/data/nigeria_lgas.csv');
            if (! is_file($path)) {
                throw new RuntimeException('The Nigerian LGA reference file is missing.');
            }

            $file = fopen($path, 'rb');
            if ($file === false) {
                throw new RuntimeException('The Nigerian LGA reference file could not be opened.');
            }

            try {
                $header = fgetcsv($file);
                if ($header !== ['state_name', 'province_name']) {
                    throw new RuntimeException('The Nigerian LGA reference file has an invalid header.');
                }

                $stateRecords = [];
                $provinceCount = 0;
                while (($row = fgetcsv($file)) !== false) {
                    if (count($row) !== 2 || trim($row[0]) === '' || trim($row[1]) === '') {
                        throw new RuntimeException('The Nigerian LGA reference file contains an invalid row.');
                    }

                    [$stateName, $provinceName] = array_map('trim', $row);
                    $state = $stateRecords[$stateName] ??= $this->upsertState($stateName, $nigeriaImport->id);
                    $province = Province::query()->firstOrNew([
                        'state_id' => $state->id,
                        'name' => $provinceName,
                    ]);
                    $province->official_type = $stateName === 'Federal Capital Territory'
                        ? 'area_council'
                        : 'local_government_area';
                    $province->source_import_id = $nigeriaImport->id;
                    $province->save();
                    $provinceCount++;
                }

                if (count($stateRecords) !== 37 || $provinceCount !== 774) {
                    throw new RuntimeException('Expected 37 Nigerian subdivisions and 774 provinces; imported '.count($stateRecords).' and '.$provinceCount.'.');
                }

                $nigeriaImport->update(['metadata' => [
                    'dataset' => 'Nigeria states and local government areas',
                    'state_count' => count($stateRecords),
                    'province_count' => $provinceCount,
                ]]);
            } finally {
                fclose($file);
            }
        });
    }

    private function upsertState(string $name, string $sourceImportId): State
    {
        $state = State::query()->firstOrNew([
            'country_code' => 'NG',
            'name' => $name,
        ]);
        $state->official_name = $name;
        $state->code = $name === 'Federal Capital Territory' ? 'FCT' : null;
        $state->subdivision_type = $name === 'Federal Capital Territory' ? 'territory' : 'state';
        $state->source_import_id = $sourceImportId;
        $state->save();

        return $state;
    }

    /** @param array<string, mixed> $metadata */
    private function recordImport(string $key, string $url, string $version, string $license, array $metadata): GeographyImport
    {
        $import = GeographyImport::query()->firstOrNew(['source_key' => $key]);
        if (! $import->exists || $import->source_version !== $version) {
            $import->source_url = $url;
            $import->source_version = $version;
            $import->license = $license;
            $import->imported_at = now();
        }
        $import->metadata = $metadata;
        $import->save();

        return $import;
    }
}
