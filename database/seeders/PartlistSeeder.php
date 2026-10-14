<?php

namespace Database\Seeders;

use App\Models\Partlist;
use Illuminate\Database\Seeder;

class PartlistSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partlists = [
            [
                'code' => '53307-BZ170FINISH GOODS', 'level' => 'FINISH GOODS', 'customer_code' => 'ADM SAP', 'model' => 'D17D',
                'part_no' => '53307-BZ170', 'part_no_fg' => '53307-BZ170', 'job_no' => 'NX-3572', 'part_name' => 'HOOK SUB-ASSY, HOOD LOCK',
                'type_box' => 'STEEL PALET', 'qty_kbn' => 50, 'process' => 'SW', 'line' => 'SW 8.1', 'line_code' => 'T8.1',
                'rack_no' => 'J-43', 'cap_rack' => 2, 'jig_no' => '53307-BZ170SW', 'qty_lot' => 2, 'stock_min' => 1, 'stock_max' => 2,
                'image_name' => '53307-BZ170-SW 8.2.jpg', 'last_routing' => 'SW 8.2', 'remark' => 'FG-OLI', 'lt_pull' => 120, 'lt_prod' => 23,
                'code_partset' => null, 'set_label' => 'Lembar', 'prod_point' => 1, 'cat_machine' => 'CT', 'spm' => 10, 'dandori' => 5,
                'cek_startfinish' => 5, 'po_day' => null,
            ],
            [
                'code' => '800401OUTGOING', 'level' => 'OUTGOING', 'customer_code' => 'SC SKI', 'model' => '-',
                'part_no' => '800401', 'part_no_fg' => '800401', 'job_no' => null, 'part_name' => 'SAGARI HINOMOTO LOCK R',
                'type_box' => '6464', 'qty_kbn' => 3000, 'process' => 'PG', 'line' => 'PG 04', 'line_code' => 'PG4',
                'rack_no' => 'OG2-16-A', 'cap_rack' => 24, 'jig_no' => '800401-PG 03', 'qty_lot' => 2, 'stock_min' => 1, 'stock_max' => 2,
                'image_name' => '800401-OG.JPG', 'last_routing' => 'PG 04', 'remark' => 'KASHIMA-OG', 'lt_pull' => 60, 'lt_prod' => 80,
                'code_partset' => '800401/800402', 'set_label' => 'Lembar', 'prod_point' => 1, 'cat_machine' => 'SPM', 'spm' => 45, 'dandori' => 17,
                'cek_startfinish' => 0, 'po_day' => null,
            ],
            [
                'code' => '800402OUTGOING', 'level' => 'OUTGOING', 'customer_code' => 'SC SKI', 'model' => '-',
                'part_no' => '800402', 'part_no_fg' => '800402', 'job_no' => null, 'part_name' => 'SAGARI HINOMOTO LOCK L',
                'type_box' => '6464', 'qty_kbn' => 3000, 'process' => 'PG', 'line' => 'PG 04', 'line_code' => 'PG4',
                'rack_no' => 'OG2-16-B', 'cap_rack' => 24, 'jig_no' => '800402-PG 03', 'qty_lot' => 2, 'stock_min' => 1, 'stock_max' => 2,
                'image_name' => '800402-OG.JPG', 'last_routing' => 'PG 04', 'remark' => 'KASHIMA-OG', 'lt_pull' => 60, 'lt_prod' => 80,
                'code_partset' => '800401/800402', 'set_label' => 'Lembar', 'prod_point' => 1, 'cat_machine' => 'SPM', 'spm' => 45, 'dandori' => 17,
                'cek_startfinish' => 0, 'po_day' => null,
            ],
        ];

        foreach ($partlists as $partlist) {
            Partlist::updateOrCreate(['code' => $partlist['code']], $partlist);
        }
    }
}
