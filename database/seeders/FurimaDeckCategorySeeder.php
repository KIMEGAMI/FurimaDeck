<?php

namespace Database\Seeders;

use App\Models\CategoryAttribute;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FurimaDeckCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $rootName => $children) {
            $root = $this->category($rootName, null, 0);

            foreach ($children as $childName => $leaves) {
                $child = $this->category($childName, $root->id, 0);

                foreach ($leaves as $leafName => $attributes) {
                    $leaf = $this->category($leafName, $child->id, 0);

                    foreach ($attributes as $key => $label) {
                        CategoryAttribute::firstOrCreate([
                            'category_id' => $leaf->id,
                            'key' => $key,
                        ], [
                            'label' => $label,
                            'input_type' => 'text',
                            'is_required' => false,
                            'sort_order' => 0,
                            'is_active' => true,
                        ]);
                    }
                }
            }
        }
    }

    /** @return array<string, array<string, array<string, array<string, string>>>> */
    private function categories(): array
    {
        return [
            'ファッション' => [
                'メンズ' => [
                    'トップス' => ['brand' => 'ブランド', 'size' => 'サイズ', 'color' => 'カラー', 'material' => '素材'],
                    'ボトムス' => ['brand' => 'ブランド', 'size' => 'サイズ', 'color' => 'カラー', 'waist' => 'ウエスト'],
                ],
                'レディース' => [
                    'トップス' => ['brand' => 'ブランド', 'size' => 'サイズ', 'color' => 'カラー', 'material' => '素材'],
                    'バッグ' => ['brand' => 'ブランド', 'color' => 'カラー', 'material' => '素材', 'size' => 'サイズ'],
                ],
            ],
            '家電' => [
                'スマートフォン' => [
                    '本体' => ['manufacturer' => 'メーカー', 'model' => '機種', 'storage' => '容量', 'sim_status' => 'SIM状態'],
                ],
                'カメラ' => [
                    'デジタルカメラ' => ['manufacturer' => 'メーカー', 'model' => '型番', 'sensor' => 'センサー', 'lens_mount' => 'レンズマウント'],
                ],
            ],
            'エンタメ' => [
                'ゲーム' => [
                    'ゲーム機本体' => ['manufacturer' => 'メーカー', 'model' => '型番', 'storage' => '容量', 'operation_checked' => '動作確認'],
                ],
                '本' => [
                    '書籍' => ['isbn' => 'ISBN', 'author' => '著者', 'publisher' => '出版社', 'volume' => '巻数'],
                ],
                'トレーディングカード' => [
                    'カード' => ['title' => 'タイトル', 'card_number' => 'カード番号', 'rarity' => 'レアリティ', 'condition_detail' => '状態詳細'],
                ],
            ],
            '生活用品' => [
                '家具' => [
                    'インテリア' => ['manufacturer' => 'メーカー', 'color' => 'カラー', 'size' => 'サイズ', 'material' => '素材'],
                ],
                '工具' => [
                    '電動工具' => ['manufacturer' => 'メーカー', 'model' => '型番', 'voltage' => '電圧', 'operation_checked' => '動作確認'],
                ],
            ],
        ];
    }

    private function category(string $name, ?int $parentId, int $sortOrder): ProductCategory
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug !== ''
            ? $baseSlug.'-'.($parentId ?? 'root')
            : 'category-'.substr(hash('sha256', ($parentId ?? 'root').'|'.$name), 0, 12);

        return ProductCategory::firstOrCreate([
            'parent_id' => $parentId,
            'name' => $name,
        ], [
            'slug' => $slug,
            'parent_id' => $parentId,
            'name' => $name,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }
}
