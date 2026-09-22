import fs from "node:fs/promises";
import path from "node:path";
import { Workbook } from "@oai/artifact-tool";

const OUTPUT_FILE = "dummy_auction_items_2026_06_2027_06.csv";
const RANDOM_SEED = 20260613;
const EXPECTED_ROW_COUNT = 244;
const CSV_HEADERS = [
  "product_id",
  "product_name",
  "condition",
  "purchase_unit_cost",
  "purchase_quantity",
  "quantity_available",
  "inventory_status",
  "description_base",
  "purchase_date",
  "purchase_shipping_cost",
  "other_purchase_expense",
  "manufacturer_model_number",
  "storage_location",
  "memo",
];
const MONTHLY_PRODUCT_COUNTS = [
  [2026, 6, 13],
  [2026, 7, 18],
  [2026, 8, 21],
  [2026, 9, 11],
  [2026, 10, 14],
  [2026, 11, 26],
  [2026, 12, 33],
  [2027, 1, 29],
  [2027, 2, 16],
  [2027, 3, 17],
  [2027, 4, 13],
  [2027, 5, 12],
  [2027, 6, 21],
];
const COLORS = ["ブラック", "ネイビー", "ブラウン", "ベージュ", "グレー", "カーキ", "ブルー", "アイボリー", "オリーブ"];
const CONDITION_CODES = ["new", "unused", "like_new", "used_good", "used", "damaged", "junk"];
const STORAGE_LOCATIONS = ["棚A-01", "棚A-02", "棚B-01", "棚B-02", "棚C-01", "ラック-03", "保管箱-01", "保管箱-02"];
const CATALOG = [
  { name: "コットンTシャツ", costMin: 400, costMax: 1900, shipping: 230, seasons: ["summer", "all"] },
  { name: "オックスフォードシャツ", costMin: 700, costMax: 2800, shipping: 230, seasons: ["spring", "summer", "autumn", "all"] },
  { name: "クルーネックスウェット", costMin: 900, costMax: 3600, shipping: 450, seasons: ["autumn", "winter", "all"] },
  { name: "ジップパーカー", costMin: 1200, costMax: 4200, shipping: 520, seasons: ["autumn", "winter"] },
  { name: "ウール混ニット", costMin: 1100, costMax: 4600, shipping: 520, seasons: ["autumn", "winter"] },
  { name: "セルビッジデニム", costMin: 1300, costMax: 5800, shipping: 520, seasons: ["all"] },
  { name: "チノトラウザー", costMin: 900, costMax: 3500, shipping: 450, seasons: ["spring", "autumn", "all"] },
  { name: "ウールスラックス", costMin: 1300, costMax: 4800, shipping: 520, seasons: ["autumn", "winter"] },
  { name: "ナイロンショーツ", costMin: 600, costMax: 2400, shipping: 230, seasons: ["summer"] },
  { name: "デニムジャケット", costMin: 2000, costMax: 7600, shipping: 750, seasons: ["spring", "autumn"] },
  { name: "レザージャケット", costMin: 5000, costMax: 18000, shipping: 1000, seasons: ["autumn", "winter"] },
  { name: "ダウンジャケット", costMin: 3500, costMax: 13000, shipping: 1200, seasons: ["winter"] },
  { name: "ウールコート", costMin: 3200, costMax: 14000, shipping: 1200, seasons: ["winter"] },
  { name: "ランニングスニーカー", costMin: 1800, costMax: 8800, shipping: 750, seasons: ["all"] },
  { name: "レザーブーツ", costMin: 2800, costMax: 12000, shipping: 1000, seasons: ["autumn", "winter"] },
  { name: "ナイロンリュック", costMin: 1400, costMax: 6000, shipping: 750, seasons: ["all"] },
  { name: "キャンバストート", costMin: 700, costMax: 3600, shipping: 520, seasons: ["all"] },
  { name: "クォーツ腕時計", costMin: 1600, costMax: 12500, shipping: 450, seasons: ["all"] },
];
const SEASON_BY_MONTH = {
  1: "winter", 2: "winter", 3: "spring", 4: "spring", 5: "spring", 6: "summer",
  7: "summer", 8: "summer", 9: "autumn", 10: "autumn", 11: "winter", 12: "winter",
};

let randomState = RANDOM_SEED;

function random() {
  randomState = (randomState * 1664525 + 1013904223) >>> 0;

  return randomState / 4294967296;
}

function integer(minimum, maximum) {
  return minimum + Math.floor(random() * (maximum - minimum + 1));
}

function sample(values) {
  return values[Math.floor(random() * values.length)];
}

function toHundreds(value) {
  return Math.max(100, Math.round(value / 100) * 100);
}

function formatDate(year, month, day) {
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function daysInMonth(year, month) {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function csvCell(value) {
  return `"${String(value).replaceAll('"', '""')}"`;
}

function seasonalCatalog(month) {
  const season = SEASON_BY_MONTH[month];
  const seasonalItems = CATALOG.filter((item) => item.seasons.includes(season));
  const allSeasonItems = CATALOG.filter((item) => item.seasons.includes("all"));

  return [...seasonalItems, ...seasonalItems, ...allSeasonItems];
}

function inventoryState() {
  const chance = random();
  if (chance < 0.64) return { status: "out_of_stock", quantity: 0 };
  if (chance < 0.84) return { status: "listed", quantity: 1 };
  if (chance < 0.95) return { status: "in_stock", quantity: 1 };

  return { status: "draft", quantity: 1 };
}

function buildRows() {
  const rows = [];
  let sequence = 1;

  for (const [year, month, count] of MONTHLY_PRODUCT_COUNTS) {
    for (let itemIndex = 0; itemIndex < count; itemIndex += 1) {
      const item = sample(seasonalCatalog(month));
      const inventory = inventoryState();
      const productId = `DEMO-${year}${String(month).padStart(2, "0")}-${String(sequence).padStart(4, "0")}`;
      const purchaseDate = formatDate(year, month, integer(1, daysInMonth(year, month)));

      rows.push({
        productId,
        productName: `${sample(COLORS)} ${item.name}`,
        condition: sample(CONDITION_CODES),
        purchaseUnitCost: toHundreds(integer(item.costMin, item.costMax)),
        purchaseQuantity: 1,
        quantityAvailable: inventory.quantity,
        inventoryStatus: inventory.status,
        descriptionBase: `デモ商品です。${item.name}の状態と相場を確認して出品してください。`,
        purchaseDate,
        purchaseShippingCost: item.shipping,
        otherPurchaseExpense: sample([0, 0, 0, 100, 200, 300]),
        manufacturerModelNumber: `DEMO-${String(sequence).padStart(5, "0")}`,
        storageLocation: sample(STORAGE_LOCATIONS),
        memo: `${year}年${month}月仕入れのデモデータ`,
      });
      sequence += 1;
    }
  }

  return rows.sort((left, right) => left.purchaseDate.localeCompare(right.purchaseDate) || left.productId.localeCompare(right.productId));
}

async function main() {
  const rows = buildRows();
  const csv = [
    CSV_HEADERS.join(","),
    ...rows.map((row) => [
      row.productId,
      row.productName,
      row.condition,
      row.purchaseUnitCost,
      row.purchaseQuantity,
      row.quantityAvailable,
      row.inventoryStatus,
      row.descriptionBase,
      row.purchaseDate,
      row.purchaseShippingCost,
      row.otherPurchaseExpense,
      row.manufacturerModelNumber,
      row.storageLocation,
      row.memo,
    ].map(csvCell).join(",")),
  ].join("\r\n").concat("\r\n");

  const outputPath = path.resolve(process.cwd(), OUTPUT_FILE);
  await fs.writeFile(outputPath, `\uFEFF${csv}`, "utf8");

  const workbook = await Workbook.fromCSV(csv, { sheetName: "Demo products" });
  const verification = await workbook.inspect({
    kind: "table",
    range: `Demo products!A1:N${rows.length + 1}`,
    include: "values",
    tableMaxRows: 4,
    tableMaxCols: CSV_HEADERS.length,
  });

  if (!verification.ndjson.includes("product_id") || rows.length !== EXPECTED_ROW_COUNT) {
    throw new Error("CSV verification failed.");
  }

  console.log(`Created ${OUTPUT_FILE} with ${rows.length} rows.`);
}

await main();
