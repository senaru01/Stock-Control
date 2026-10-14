<?php

namespace Database\Factories;

use App\Models\Partlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partlist>
 */
class PartlistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $partNo = fake()->unique()->numerify('######');
        $level = fake()->randomElement(['FINISH GOODS', 'OUTGOING', 'WIP']);
        $line = fake()->randomElement(['PG 04', 'SW 8.1', 'SW 8.2']);

        return [
            'code' => $partNo.$level,
            'level' => $level,
            'customer_code' => fake()->randomElement(['ADM SAP', 'SC SKI']),
            'model' => fake()->bothify('???#?'),
            'part_no' => $partNo,
            'part_no_fg' => $partNo,
            'job_no' => fake()->bothify('NX-####'),
            'part_name' => strtoupper(fake()->words(3, true)),
            'type_box' => fake()->randomElement(['STEEL PALET', '6464']),
            'qty_kbn' => fake()->numberBetween(10, 3000),
            'process' => fake()->randomElement(['PG', 'SW']),
            'line' => $line,
            'line_code' => str_replace(' ', '', $line),
            'rack_no' => fake()->bothify('OG#-##-?'),
            'cap_rack' => fake()->numberBetween(1, 30),
            'jig_no' => $partNo.'-'.$line,
            'qty_lot' => fake()->numberBetween(1, 5),
            'stock_min' => 1,
            'stock_max' => 2,
            'image_name' => $partNo.'.jpg',
            'last_routing' => $line,
            'remark' => fake()->randomElement(['FG-OLI', 'KASHIMA-OG']),
            'lt_pull' => fake()->numberBetween(30, 120),
            'lt_prod' => fake()->numberBetween(10, 90),
            'code_partset' => null,
            'set_label' => 'Lembar',
            'prod_point' => 1,
            'cat_machine' => fake()->randomElement(['CT', 'SPM']),
            'spm' => fake()->numberBetween(5, 50),
            'dandori' => fake()->numberBetween(0, 20),
            'cek_startfinish' => fake()->numberBetween(0, 5),
            'po_day' => null,
        ];
    }
}
