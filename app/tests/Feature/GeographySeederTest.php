<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\GeographyImport;
use App\Models\Province;
use App\Models\State;
use Database\Seeders\GeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeographySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_geography_is_complete_traceable_and_idempotent(): void
    {
        $seeder = app(GeographySeeder::class);
        $seeder->run();

        $this->assertGreaterThan(200, Country::query()->count());
        $this->assertSame(37, State::query()->where('country_code', 'NG')->count());
        $this->assertSame(774, Province::query()->whereHas('state', fn ($query) => $query->where('country_code', 'NG'))->count());
        $this->assertSame(6, Province::query()
            ->where('official_type', 'area_council')
            ->whereHas('state', fn ($query) => $query->where('country_code', 'NG')->where('code', 'FCT'))
            ->count());
        $this->assertDatabaseHas('geography_imports', [
            'source_key' => 'aetechlabs/nigeria-states-lgas',
            'source_version' => '9382dd39696e647f72e026e575ec836e1b9a92b9',
        ]);
        $this->assertDatabaseHas('geography_imports', [
            'source_key' => 'symfony/intl-country-names',
        ]);

        $seeder->run();

        $this->assertSame(37, State::query()->where('country_code', 'NG')->count());
        $this->assertSame(774, Province::query()->whereHas('state', fn ($query) => $query->where('country_code', 'NG'))->count());
        $this->assertSame(2, GeographyImport::query()->count());
    }
}
