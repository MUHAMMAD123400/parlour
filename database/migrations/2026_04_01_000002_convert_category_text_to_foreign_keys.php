<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('brand')->constrained('categories')->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('service_name')->constrained('categories')->nullOnDelete();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('item_type')->constrained('categories')->nullOnDelete();
        });

        $categoryMap = DB::table('categories')
            ->pluck('id', 'category_name')
            ->toArray();

        $collectCategoryNames = function (string $tableName, string $columnName) {
            return DB::table($tableName)
                ->whereNotNull($columnName)
                ->where($columnName, '!=', '')
                ->distinct()
                ->pluck($columnName)
                ->toArray();
        };

        $allCategoryNames = array_unique(array_merge(
            $collectCategoryNames('products', 'category'),
            $collectCategoryNames('services', 'category'),
            $collectCategoryNames('bill_items', 'category')
        ));

        $discounts = DB::table('discounts')->select('id', 'categories')->get();
        foreach ($discounts as $discount) {
            if (empty($discount->categories)) {
                continue;
            }

            $decoded = json_decode($discount->categories, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $allCategoryNames[] = trim($value);
                }
            }
        }

        $allCategoryNames = array_unique($allCategoryNames);

        foreach ($allCategoryNames as $categoryName) {
            if (!isset($categoryMap[$categoryName])) {
                $newId = DB::table('categories')->insertGetId([
                    'category_name' => $categoryName,
                    'color' => '#808080',
                    'status' => '1',
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $categoryMap[$categoryName] = $newId;
            }
        }

        foreach ($categoryMap as $categoryName => $categoryId) {
            DB::table('products')->where('category', $categoryName)->update(['category_id' => $categoryId]);
            DB::table('services')->where('category', $categoryName)->update(['category_id' => $categoryId]);
            DB::table('bill_items')->where('category', $categoryName)->update(['category_id' => $categoryId]);
        }

        foreach ($discounts as $discount) {
            if (empty($discount->categories)) {
                continue;
            }

            $decoded = json_decode($discount->categories, true);
            if (!is_array($decoded)) {
                continue;
            }

            $mappedIds = [];
            foreach ($decoded as $value) {
                if (is_int($value)) {
                    $mappedIds[] = $value;
                    continue;
                }

                if (is_string($value)) {
                    $name = trim($value);
                    if ($name !== '' && isset($categoryMap[$name])) {
                        $mappedIds[] = $categoryMap[$name];
                    }
                }
            }

            DB::table('discounts')
                ->where('id', $discount->id)
                ->update(['categories' => json_encode(array_values(array_unique($mappedIds)))]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable()->after('brand');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('category')->nullable()->after('service_name');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->string('category')->nullable()->after('item_type');
        });

        $categories = DB::table('categories')->pluck('category_name', 'id')->toArray();

        DB::table('products')->select('id', 'category_id')->orderBy('id')->chunkById(200, function ($rows) use ($categories) {
            foreach ($rows as $row) {
                DB::table('products')
                    ->where('id', $row->id)
                    ->update(['category' => $categories[$row->category_id] ?? null]);
            }
        });

        DB::table('services')->select('id', 'category_id')->orderBy('id')->chunkById(200, function ($rows) use ($categories) {
            foreach ($rows as $row) {
                DB::table('services')
                    ->where('id', $row->id)
                    ->update(['category' => $categories[$row->category_id] ?? null]);
            }
        });

        DB::table('bill_items')->select('id', 'category_id')->orderBy('id')->chunkById(200, function ($rows) use ($categories) {
            foreach ($rows as $row) {
                DB::table('bill_items')
                    ->where('id', $row->id)
                    ->update(['category' => $categories[$row->category_id] ?? null]);
            }
        });

        $discounts = DB::table('discounts')->select('id', 'categories')->get();
        foreach ($discounts as $discount) {
            if (empty($discount->categories)) {
                continue;
            }

            $decoded = json_decode($discount->categories, true);
            if (!is_array($decoded)) {
                continue;
            }

            $mappedNames = [];
            foreach ($decoded as $value) {
                if (is_numeric($value)) {
                    $mappedNames[] = $categories[(int) $value] ?? null;
                } elseif (is_string($value)) {
                    $mappedNames[] = $value;
                }
            }

            $mappedNames = array_values(array_filter(array_unique($mappedNames)));

            DB::table('discounts')
                ->where('id', $discount->id)
                ->update(['categories' => json_encode($mappedNames)]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
