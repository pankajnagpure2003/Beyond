<?php

namespace Botble\Ecommerce\Models;

use Botble\Base\Facades\AdminHelper;
use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Enums\SpecificationAttributeFieldType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class SpecificationAttribute extends BaseModel
{
    protected $table = 'ec_specification_attributes';

    protected $fillable = [
        'author_type',
        'author_id',
        'group_id',
        'name',
        'type',
        'options',
        'default_value',
    ];

    protected $casts = [
        'options' => 'array',
        'type' => SpecificationAttributeFieldType::class,
    ];

    protected static function booted(): void
    {
        if (AdminHelper::isInAdmin(true)) {
            static::addGlobalScope('admin', function ($query): void {
                $query->whereNull('author_id');
            });
        }

        static::saving(function (self $attribute): void {
            if (! is_array($attribute->options) || empty($attribute->options)) {
                return;
            }

            $options = $attribute->options;
            $needsUpdate = false;

            foreach ($options as &$opt) {
                if (is_array($opt) && empty($opt['id'])) {
                    $opt['id'] = self::generateOptionId();
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate) {
                $attribute->options = $options;
            }
        });

        static::deleted(function (self $attribute): void {
            $attribute->products()->detach();
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ec_product_specification_attribute', 'attribute_id', 'product_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SpecificationGroup::class, 'group_id');
    }

    public static function generateOptionId(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, [
            SpecificationAttributeFieldType::SELECT,
            SpecificationAttributeFieldType::RADIO,
        ]);
    }

    public function hasIdBasedOptions(): bool
    {
        $options = $this->options;

        if (! is_array($options) || empty($options)) {
            return false;
        }

        // Rows written before options carried ids hold plain strings, and a partially migrated row
        // holds a mix of both, so every element is inspected rather than only the first one.
        foreach ($options as $option) {
            if (is_array($option) && isset($option['id'])) {
                return true;
            }
        }

        return false;
    }

    public function getIdBasedOptions(): array
    {
        $options = $this->options;

        if (! is_array($options) || empty($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $option) {
            // Options come from user input, imports and older schema versions, so an element may be a
            // plain string, an id/value pair, or an array carrying neither. Everything is coerced to a
            // pair of strings here so callers - the Blade views above all - never echo an array and
            // trigger the PHP 8 "htmlspecialchars(): Argument #1 must be of type string" fatal.
            $value = self::castOptionValue(is_array($option) ? ($option['value'] ?? null) : $option);
            $id = is_array($option) ? ($option['id'] ?? null) : null;
            $id = is_scalar($id) ? (string) $id : '';

            $normalized[] = [
                // Missing ids are DERIVED from the value, never generated randomly: this is a read
                // path, and the importer and the edit form persist the id it returns. A random id
                // would differ on the next call, leaving the stored id unresolvable and printing raw
                // hex where the label should be.
                'id' => $id !== '' ? $id : self::deriveOptionId($value),
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * A stable id for an option that has none, derived from its own value.
     *
     * Same shape as generateOptionId() so both are interchangeable to callers, but repeatable - two
     * calls on the same unmigrated row agree, which is what keeps a stored id resolvable.
     */
    protected static function deriveOptionId(string $value): string
    {
        return substr(md5($value), 0, 8);
    }

    /**
     * Flatten one option value down to a printable string.
     *
     * Translation payloads occasionally arrive as a nested array keyed by language, so the first
     * scalar found is used instead of discarding the option outright.
     */
    public static function castOptionValue(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    return (string) $item;
                }
            }

            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The option labels only, already cast to strings, for views that render values rather than ids.
     */
    public function getPlainOptions(): array
    {
        return array_column($this->getIdBasedOptions(), 'value');
    }

    public function getOptionValueById(string $id): ?string
    {
        foreach ($this->getIdBasedOptions() as $option) {
            if ($option['id'] === $id) {
                return $option['value'];
            }
        }

        return null;
    }

    public function getOptionIdByValue(string $value): ?string
    {
        foreach ($this->getIdBasedOptions() as $option) {
            if ($option['value'] === $value) {
                return $option['id'];
            }
        }

        return null;
    }

    public function getDefaultLanguageOptions(): array
    {
        $rawOptions = DB::table('ec_specification_attributes')
            ->where('id', $this->getKey())
            ->value('options');

        $options = json_decode($rawOptions ?: '', true) ?: [];

        if (empty($options)) {
            return [];
        }

        $first = $options[0] ?? null;

        if (is_array($first) && isset($first['id'])) {
            return $options;
        }

        return array_map(fn (string $value) => [
            'id' => self::generateOptionId(),
            'value' => $value,
        ], $options);
    }
}
