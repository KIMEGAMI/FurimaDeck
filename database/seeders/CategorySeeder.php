<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'トップス' => ['Tシャツ', 'シャツ', 'スウェット', 'パーカー', 'ニット'],
            'ボトムス' => ['デニム', 'チノパン', 'スラックス', 'ショートパンツ'],
            'アウター' => ['Gジャン', 'レザージャケット', 'ダウン', 'コート', 'ナイロンジャケット'],
            'シューズ' => ['スニーカー', 'ブーツ', '革靴', 'サンダル'],
            'バッグ' => ['リュック', 'トートバッグ', 'ショルダーバッグ', 'ボストンバッグ'],
            'アクセサリー' => ['帽子', 'ベルト', '腕時計', 'ネックレス'],
            'その他' => ['その他'],
            'ファッション' => [
                'メンズ' => ['トップス', 'ボトムス', 'アウター', '靴', 'バッグ・小物'],
                'レディース' => ['トップス', 'ボトムス', 'ワンピース', 'アウター', '靴・バッグ'],
                'キッズ・ベビー' => ['トップス', 'ボトムス', 'ワンピース', '靴', 'その他'],
            ],
            '家電・スマホ・カメラ' => [
                'スマホ・タブレット' => ['スマートフォン', 'タブレット', 'アクセサリー', 'その他'],
                'PC・周辺機器' => ['パソコン', 'モニター', '周辺機器', 'パーツ'],
                'カメラ' => ['デジタルカメラ', 'レンズ', 'ビデオカメラ', 'アクセサリー'],
                '生活家電' => ['キッチン家電', '掃除・洗濯', '空調・季節家電', 'その他'],
            ],
            '本・音楽・ゲーム' => [
                '本' => ['小説・文芸', '漫画', '雑誌', '専門書'],
                '音楽・映像' => ['CD', 'DVD・Blu-ray', 'レコード', 'その他'],
                'ゲーム' => ['ゲームソフト', 'ゲーム機本体', '周辺機器', 'その他'],
            ],
            'ホビー・スポーツ' => [
                'おもちゃ・コレクション' => ['フィギュア', 'トレーディングカード', 'プラモデル', 'その他'],
                '楽器' => ['ギター・ベース', '鍵盤楽器', '管楽器', 'アクセサリー'],
                'スポーツ・アウトドア' => ['スポーツ用品', 'アウトドア用品', '釣り用品', 'その他'],
            ],
            '美容・健康' => [
                'コスメ' => ['メイクアップ', 'スキンケア', '香水', 'ネイル'],
                '美容家電・健康用品' => ['美容家電', '健康用品', 'ダイエット用品', 'その他'],
            ],
            'インテリア・日用品' => [
                '家具・インテリア' => ['家具', '照明', '寝具', 'インテリア雑貨'],
                'キッチン・日用品' => ['食器', '調理器具', '生活雑貨', 'その他'],
                'ハンドメイド' => ['アクセサリー', 'ファッション', '雑貨', '素材'],
            ],
        ];

        $parentOrder = 0;

        foreach ($categories as $parentName => $children) {
            $parent = Category::query()->updateOrCreate(
                ['parent_id' => null, 'name' => $parentName],
                ['sort_order' => $parentOrder]
            );

            if (array_is_list($children)) {
                foreach ($children as $childOrder => $childName) {
                    Category::query()->updateOrCreate(
                        ['parent_id' => $parent->id, 'name' => $childName],
                        ['sort_order' => $childOrder]
                    );
                }

                $parentOrder++;

                continue;
            }

            $middlePosition = 0;

            foreach ($children as $middleName => $leafCategories) {
                $middle = Category::query()->updateOrCreate(
                    ['parent_id' => $parent->id, 'name' => $middleName],
                    ['sort_order' => $middlePosition]
                );

                foreach ($leafCategories as $leafOrder => $leafName) {
                    Category::query()->updateOrCreate(
                        ['parent_id' => $middle->id, 'name' => $leafName],
                        ['sort_order' => $leafOrder]
                    );
                }

                $middlePosition++;
            }

            $parentOrder++;
        }
    }
}
