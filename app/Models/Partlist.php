<?php

namespace App\Models;

use Database\Factories\PartlistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

#[Table(key: 'code', keyType: 'string', incrementing: false)]
#[Fillable([
    'code', 'level', 'customer_code', 'model', 'part_no', 'part_no_fg', 'job_no', 'part_name',
    'type_box', 'qty_kbn', 'process', 'line', 'line_code', 'rack_no', 'cap_rack', 'jig_no',
    'qty_lot', 'stock_min', 'stock_max', 'image_name', 'last_routing', 'remark', 'lt_pull',
    'lt_prod', 'code_partset', 'set_label', 'prod_point', 'cat_machine', 'spm', 'dandori',
    'cek_startfinish', 'po_day',
])]
class Partlist extends Model
{
    /** @use HasFactory<PartlistFactory> */
    use HasFactory;

    /**
     * Levels left out of andon monitoring (service parts are not part of regular monitoring).
     *
     * @var list<string>
     */
    public const ANDON_EXCLUDED_LEVELS = ['HOLD'];

    /**
     * Process whose parts get a material order point; other processes show 0 for now.
     */
    public const ORDER_POINT_PROCESS = 'PG';

    /**
     * Selectable order-point days for the material order point.
     *
     * @var list<int>
     */
    public const ORDER_POINT_DAY_OPTIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public const DEFAULT_ORDER_POINT_DAYS = 1;

    protected const ORDER_POINT_DAYS_SETTING = 'partlist.order_point_days';

    /**
     * The saved order-point days used for MAT_ORDERPOINT on the partlist and the andon.
     */
    public static function orderPointDays(): int
    {
        $days = (int) Setting::read(self::ORDER_POINT_DAYS_SETTING, self::DEFAULT_ORDER_POINT_DAYS);

        return in_array($days, self::ORDER_POINT_DAY_OPTIONS, true) ? $days : self::DEFAULT_ORDER_POINT_DAYS;
    }

    public static function saveOrderPointDays(int $days): void
    {
        Setting::write(self::ORDER_POINT_DAYS_SETTING, in_array($days, self::ORDER_POINT_DAY_OPTIONS, true) ? $days : self::DEFAULT_ORDER_POINT_DAYS);
    }

    /**
     * Limit the query to partlists that are monitored on the andon.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function monitoredOnAndon(Builder $query): void
    {
        $query->whereNotIn('level', self::ANDON_EXCLUDED_LEVELS);
    }

    /**
     * Partlist columns in display order, with their label, input type and max length.
     *
     * @var array<string, array{label: string, type: 'string'|'integer', max?: int, required?: bool}>
     */
    public const FIELDS = [
        'code' => ['label' => 'Code', 'type' => 'string', 'max' => 100, 'required' => true],
        'level' => ['label' => 'Level', 'type' => 'string', 'max' => 50, 'required' => true],
        'customer_code' => ['label' => 'Customer Code', 'type' => 'string', 'max' => 50],
        'model' => ['label' => 'Model', 'type' => 'string', 'max' => 50],
        'part_no' => ['label' => 'Part No', 'type' => 'string', 'max' => 50, 'required' => true],
        'part_no_fg' => ['label' => 'Part No FG', 'type' => 'string', 'max' => 50],
        'job_no' => ['label' => 'Job No', 'type' => 'string', 'max' => 50],
        'part_name' => ['label' => 'Part Name', 'type' => 'string', 'max' => 255],
        'type_box' => ['label' => 'Type Box', 'type' => 'string', 'max' => 50],
        'qty_kbn' => ['label' => 'Qty KBN', 'type' => 'integer'],
        'process' => ['label' => 'Process', 'type' => 'string', 'max' => 50],
        'line' => ['label' => 'Line', 'type' => 'string', 'max' => 50],
        'line_code' => ['label' => 'Line Code', 'type' => 'string', 'max' => 50],
        'rack_no' => ['label' => 'Rack No', 'type' => 'string', 'max' => 50],
        'cap_rack' => ['label' => 'Cap Rack', 'type' => 'integer'],
        'jig_no' => ['label' => 'Jig No', 'type' => 'string', 'max' => 100],
        'qty_lot' => ['label' => 'Qty Lot', 'type' => 'integer'],
        'stock_min' => ['label' => 'Stock Min', 'type' => 'integer'],
        'stock_max' => ['label' => 'Stock Max', 'type' => 'integer'],
        'image_name' => ['label' => 'Image Name', 'type' => 'string', 'max' => 255],
        'last_routing' => ['label' => 'Last Routing', 'type' => 'string', 'max' => 50],
        'remark' => ['label' => 'Remark', 'type' => 'string', 'max' => 255],
        'lt_pull' => ['label' => 'LT Pull', 'type' => 'integer'],
        'lt_prod' => ['label' => 'LT Prod', 'type' => 'integer'],
        'code_partset' => ['label' => 'Code Partset', 'type' => 'string', 'max' => 100],
        'set_label' => ['label' => 'Set Label', 'type' => 'string', 'max' => 50],
        'prod_point' => ['label' => 'Prod Point', 'type' => 'integer'],
        'cat_machine' => ['label' => 'Cat Machine', 'type' => 'string', 'max' => 50],
        'spm' => ['label' => 'SPM', 'type' => 'integer'],
        'dandori' => ['label' => 'Dandori', 'type' => 'integer'],
        'cek_startfinish' => ['label' => 'Cek Start Finish', 'type' => 'integer'],
        'po_day' => ['label' => 'PO Day', 'type' => 'string', 'max' => 50],
    ];

    /**
     * Material order point in KBN: PO Day x order-point days / Qty KBN, rounded up to whole KBN.
     * Only PG process parts get an order point, others are 0. Null when PO Day is not a number or Qty KBN is zero.
     */
    public function materialOrderPoint(int $days): ?int
    {
        if (strtoupper(trim((string) $this->process)) !== self::ORDER_POINT_PROCESS) {
            return 0;
        }

        if (! is_numeric($this->po_day) || $this->qty_kbn <= 0) {
            return null;
        }

        return (int) ceil((float) $this->po_day * $days / $this->qty_kbn);
    }

    /**
     * Loading in pcs/minute: SPM x percent. SPH machines are converted to per minute first (SPM / 60);
     * any other Cat Machine (e.g. 0) has no loading.
     */
    public function loading(int $percent): float
    {
        $strokesPerMinute = match (strtoupper(trim((string) $this->cat_machine))) {
            'SPH' => $this->spm / 60,
            'SPM' => (float) $this->spm,
            default => 0.0,
        };

        return $strokesPerMinute * $percent / 100;
    }

    /**
     * Validation rules for a partlist record, keyed by column name.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(bool $uniqueCode = true, ?string $ignoreCode = null): array
    {
        $rules = [];

        foreach (self::FIELDS as $name => $field) {
            $rules[$name] = match ($field['type']) {
                'integer' => ['required', 'integer', 'min:0', 'max:4294967295'],
                default => [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:'.$field['max']],
            };
        }

        if ($uniqueCode) {
            $rules['code'][] = Rule::unique('partlists', 'code')->ignore($ignoreCode, 'code');
        }

        return $rules;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_kbn' => 'integer',
            'cap_rack' => 'integer',
            'qty_lot' => 'integer',
            'stock_min' => 'integer',
            'stock_max' => 'integer',
            'lt_pull' => 'integer',
            'lt_prod' => 'integer',
            'prod_point' => 'integer',
            'spm' => 'integer',
            'dandori' => 'integer',
            'cek_startfinish' => 'integer',
        ];
    }
}
