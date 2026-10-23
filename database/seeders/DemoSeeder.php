<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Counter;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two made-up businesses so the app has something to show. Nothing here is a real company,
 * person or phone number. Every account has the password "password" and is for local use only.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->klinik();
        $this->barbershop();
    }

    private function klinik(): void
    {
        $business = Business::create(['name' => 'Klinik Gigi Senyum', 'slug' => 'klinik-gigi-senyum']);

        // Open Monday to Saturday.
        $hours = array_fill_keys(range(1, 5), ['08:00', '20:00']) + [6 => ['08:00', '14:00'], 0 => null];

        $kota = $this->branch($business, 'Senyum Salatiga Kota', 'senyum-salatiga-kota', 'Salatiga', $hours);
        $tingkir = $this->branch($business, 'Senyum Tingkir', 'senyum-tingkir', 'Salatiga', $hours);

        $serviceSets = [
            [$kota, 3, [
                ['A', 'Pemeriksaan umum', 15, 0],
                ['B', 'Scaling karang gigi', 30, 50000],
                ['C', 'Tambal gigi', 30, 40000],
                ['D', 'Cabut gigi', 20, 35000],
            ]],
            [$tingkir, 2, [
                ['A', 'Pemeriksaan umum', 15, 0],
                ['B', 'Scaling karang gigi', 30, 50000],
            ]],
        ];

        foreach ($serviceSets as [$branch, $counters, $services]) {
            $this->services($branch, $counters, $services);
        }

        $this->user($business, 'Sari Wulandari', 'owner@senyum.test', Role::Owner);
        $this->user($business, 'Dimas Prakoso', 'dimas@senyum.test', Role::Staff, $kota);
        $this->user($business, 'Ayu Lestari', 'ayu@senyum.test', Role::Staff, $tingkir);
    }

    private function barbershop(): void
    {
        $business = Business::create(['name' => 'Barbershop Kumis Tipis', 'slug' => 'barbershop-kumis-tipis']);

        $branch = $this->branch(
            $business,
            'Kumis Tipis Pasar Raya',
            'kumis-tipis',
            'Salatiga',
            array_fill_keys(range(0, 6), ['10:00', '21:00']),
        );

        $this->services($branch, 3, [
            ['A', 'Potong rambut', 30, 30000],
            ['B', 'Cukur jenggot', 15, 20000],
            ['C', 'Paket lengkap', 45, 50000],
        ]);

        $this->user($business, 'Bagas Nugroho', 'owner@kumis.test', Role::Owner);
        $this->user($business, 'Rendra Saputra', 'rendra@kumis.test', Role::Staff, $branch);
    }

    /** @param  array<int, array{0: string, 1: string}|null>  $hours */
    private function branch(Business $business, string $name, string $slug, string $city, array $hours): Branch
    {
        return Branch::create([
            'business_id' => $business->id,
            'name' => $name,
            'slug' => $slug,
            'city' => $city,
            'timezone' => 'Asia/Jakarta',
            'opening_hours' => $hours,
        ]);
    }

    /**
     * Every counter serves every service of its branch.
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $services
     */
    private function services(Branch $branch, int $counters, array $services): void
    {
        $created = collect($services)->map(fn (array $s) => Service::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'code' => $s[0],
            'name' => $s[1],
            'avg_duration_minutes' => $s[2],
            'deposit_amount' => $s[3],
        ]));

        foreach (range(1, $counters) as $number) {
            $counter = Counter::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'number' => $number]);
            $counter->services()->attach($created->pluck('id'));
        }
    }

    private function user(Business $business, string $name, string $email, Role $role, ?Branch $branch = null): void
    {
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'email_verified_at' => now(),
            'business_id' => $business->id,
            'branch_id' => $branch?->id,
            'role' => $role,
        ]);
    }
}
