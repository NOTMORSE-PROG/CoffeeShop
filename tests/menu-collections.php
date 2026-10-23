<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/shared/security.php';
require dirname(__DIR__).'/shared/menu-collections.php';
require dirname(__DIR__).'/shared/reporting.php';
$n=0;
function verify_collection(bool $ok, string $label): void { global $n; if(!$ok) throw new RuntimeException($label); $n++; }
if (!SMS_DRY_RUN) { fwrite(STDERR, 'These tests require a local dry-run database.'); exit(1); }
db()->beginTransaction();
try {
    verify_collection(report_date_range('','') === [date('Y-m-d'),date('Y-m-d')], 'Today default');
    verify_collection(menu_collection_mutation_error('category_delete',['id'=>-1])!==null,'Protected delete');
    verify_collection(menu_collection_mutation_error('category_save',['id'=>-1,'name'=>'Renamed'])!==null,'Protected rename');
    verify_collection(menu_collection_mutation_error('category_save',['name'=>'BEST SELLERS'])!==null,'Reserved name');
    $ids=array_map('intval',array_column(db_all('SELECT id FROM products ORDER BY id'),'id'));
    verify_collection(menu_collection_mutation_error('product_batch_delete',['product_ids'=>$ids])!==null,'Bulk empty prevention');
    db_query("UPDATE orders SET status='cancelled' WHERE status='completed'");
    $fallback=menu_best_sellers();
    verify_collection($fallback['fallback'] && count($fallback['products'])===1,'Honest no-sales fallback');
    $id=(int)$fallback['products'][0]['id']; $cat=(int)$fallback['products'][0]['category_id'];
    db_query('UPDATE products SET is_available=0 WHERE id<>?',[$id]);
    verify_collection(menu_collection_mutation_error('product_delete',['id'=>$id])!==null,'Final drink deletion');
    verify_collection(menu_collection_mutation_error('product_batch_delete',['product_ids'=>[$id]])!==null,'Final drink bulk deletion');
    verify_collection(menu_collection_mutation_error('product_availability',['id'=>$id])!==null,'Final drink hide');
    verify_collection(menu_collection_mutation_error('product_save',['id'=>$id,'category_id'=>$cat])!==null,'Final drink edit hide');
    verify_collection(menu_collection_mutation_error('category_save',['id'=>$cat,'name'=>'Ordinary category'])!==null,'Final category hide');
    $otherCat=(int)db_value('SELECT id FROM categories WHERE id<>? LIMIT 1',[$cat]);
    db_query('UPDATE categories SET is_active=0 WHERE id=?',[$otherCat]);
    verify_collection(menu_collection_mutation_error('product_save',['id'=>$id,'category_id'=>$otherCat,'is_available'=>'1'])!==null,'Move final drink into hidden category');
    verify_collection(menu_collection_mutation_error('product_save',['id'=>$id,'category_id'=>$cat,'is_available'=>'1'])===null,'Ordinary edit allowed');
    verify_collection(menu_collection_mutation_error('category_save',['id'=>$cat,'name'=>'Ordinary category','is_active'=>'1'])===null,'Ordinary category edit allowed');
    $second=$ids[0]===$id?$ids[1]:$ids[0];
    db_query('UPDATE products SET is_available=1, category_id=? WHERE id=?',[$cat,$second]);
    verify_collection(menu_collection_mutation_error('product_availability',['id'=>$id])===null,'Hide one when another remains');
    db_query('UPDATE products SET is_available=0 WHERE id=?',[$id]);
    verify_collection((int)menu_best_sellers()['products'][0]['id']===$second,'Fallback follows available drink');
    echo "PASS: $n collection/date regression checks\n";
} finally { db()->rollBack(); }
