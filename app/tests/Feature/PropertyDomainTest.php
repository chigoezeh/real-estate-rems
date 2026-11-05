<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\City;
use App\Models\Country;
use App\Models\LandTitleRecord;
use App\Models\Project;
use App\Models\PropertyPrototype;
use App\Models\PropertyUnit;
use App\Models\Province;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_geography_property_prototype_and_title_records_are_related(): void
    {
        $owner = $this->installOwner();
        $organization = $owner->organizations()->firstOrFail();
        $country = Country::firstOrCreate(['code' => 'NG'], [
            'name' => 'Nigeria',
            'currency_code' => 'NGN',
            'default_timezone' => 'Africa/Lagos',
        ]);
        $state = State::firstOrCreate(
            ['country_code' => $country->code, 'name' => 'Lagos'],
            ['code' => 'NG-LA'],
        );
        $province = Province::firstOrCreate(
            ['state_id' => $state->id, 'name' => 'Eti-Osa'],
            ['official_type' => 'local_government_area'],
        );
        $otherProvince = Province::firstOrCreate(
            ['state_id' => $state->id, 'name' => 'Ikeja'],
            ['official_type' => 'local_government_area'],
        );
        $city = City::firstOrCreate(
            ['country_code' => $country->code, 'state_id' => $state->id, 'name' => 'Lagos'],
            ['place_type' => 'metropolitan_area'],
        );
        $city->provinces()->attach([$province->id, $otherProvince->id]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Coastal Gardens',
            'code' => 'CG-01',
            'country_code' => $country->code,
            'state_id' => $state->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'district_or_area' => 'Lekki Phase 1',
            'settings' => [],
        ]);
        $prototype = PropertyPrototype::create([
            'project_id' => $project->id,
            'name' => 'Four-bedroom terrace',
            'bedrooms' => 4,
            'bathrooms' => 4,
            'toilets' => 5,
            'living_rooms' => 2,
            'has_bq' => true,
            'floor_area_sqm' => 220,
            'base_price' => 185000000,
        ]);
        $property = PropertyUnit::create([
            'project_id' => $project->id,
            'prototype_id' => $prototype->id,
            'phase' => 'Phase 1',
            'block' => 'A',
            'code' => 'A-12',
            'plot_number' => '12',
            'beacon_number' => 'B-0098',
            'unit_type' => 'house',
            'base_price' => 185000000,
            'finishing_state' => 'semi_finished',
            'status' => 'available',
        ]);
        $amenity = Amenity::create(['name' => 'Paved roads', 'category' => 'infrastructure']);
        $project->amenities()->attach($amenity->id, ['notes' => 'Estate-wide roads']);
        $titleRecord = LandTitleRecord::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'registered_survey',
            'reference_number' => 'SUR-2026-01',
            'jurisdiction' => 'Lagos State',
            'verification_status' => 'pending',
        ]);

        $this->assertSame('NG', $project->fresh()->country->code);
        $this->assertSame('Lagos', $project->fresh()->city->name);
        $this->assertSame(2, $city->fresh()->provinces()->count());
        $this->assertSame('Four-bedroom terrace', $property->fresh()->prototype->name);
        $this->assertTrue($property->fresh()->prototype->has_bq);
        $this->assertSame('Paved roads', $project->fresh()->amenities->first()->name);
        $this->assertSame($organization->id, $titleRecord->fresh()->organization_id);
    }

    private function installOwner(): User
    {
        $this->post('/setup', [
            'organization_name' => 'Coastal Realty',
            'country' => 'NG',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'administrator_name' => 'Ada Okafor',
            'administrator_email' => 'ada@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        return User::query()->where('email', 'ada@example.test')->firstOrFail();
    }
}
