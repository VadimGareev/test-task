<?php
// Буду писать комментарии того, что тут делаю, в реальном проекте их конечно не будет)

// Подключаем ядро
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Основная функция отправки товаров. Принимет в себя id инфоблока каталога, родительскую секциюю(0 = корень) и url принимающей стороны
function exportCatalogSection($iblockId, $rootSectionId, $targetUrl)
{
    // Подключаем необходимые модули
    // Без них классы CIBlock CCatalog не будут доступны
    if (!\Bitrix\Main\Loader::includeModule('iblock')
        || !\Bitrix\Main\Loader::includeModule('catalog')) {
        die('Не удалось подключить модули iblock или catalog');
    }

    // Определяем максимальное количество товаров в одном пакете
    // Это нужно, чтобы не превысить лимиты памяти и времени выполнения скрипта
    $maxPerPackage = 2000;

    // Собираем все разделы, которые участвуют в экспорте
    // Собираем всё дерево, чтобы на принимающей стороне можно было восстановить иерархию
    $sectionsMap = [];
    $rootSectionFilter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];
    if ($rootSectionId > 0) {
        $rootSectionFilter['SECTION_ID'] = $rootSectionId;
        // Не забываем включить сам корневой раздел в список собираемых,
        // чтобы у принимающей стороны были его данные
        $sectionsMap[$rootSectionId] = true;
    }

    // Получаем все разделы рекурсивно
    // Используем сортировку по left_margin, чтобы обойти дерево сверху вниз
    $sectionList = CIBlockSection::GetList(
        ['left_margin' => 'asc'],
        $rootSectionFilter,
        false,
        ['ID', 'NAME', 'CODE', 'DESCRIPTION', 'SECTION_PAGE_URL', 'PICTURE',
         'DETAIL_PICTURE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'LEFT_MARGIN', 'RIGHT_MARGIN']
    );
    while ($section = $sectionList->Fetch()) {
        $sectionsMap[$section['ID']] = $section;
    }

    // Дополняем разделы информацией о дочерних разделах
    // Это нужно, чтобы принимающая сторона могла корректно выстроить иерархию
    foreach ($sectionsMap as $id => &$section) {
        $section['CHILDREN'] = [];
        // Пробегаем по всем разделам и ищем те, у которых родитель равен текущему ID
        foreach ($sectionsMap as $childId => $child) {
            if ($child['IBLOCK_SECTION_ID'] == $id) {
                $section['CHILDREN'][] = $childId;
            }
        }
    }
    unset($section);

    // Собираем ID всех товаров в нужных разделах (включая подразделы)
    // Это нужно, чтобы затем разбить товары на пакеты
    $productIds = [];
    $sectionIdsForProducts = array_keys($sectionsMap);
    if (!empty($sectionIdsForProducts)) {
        $productList = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $sectionIdsForProducts, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'IBLOCK_SECTION_ID']
        );
        while ($product = $productList->Fetch()) {
            $productIds[] = $product['ID'];
        }
    }

    // Если товаров нет, завершаем работу
    if (empty($productIds)) {
        echo "Нет товаров для экспорта";
        return;
    }

    // Разбиваем товары на пакеты и отправляем
    $totalProducts = count($productIds);
    $packageCount = ceil($totalProducts / $maxPerPackage);

    // Будем пользовать штатный http клмент битры
    $httpClient = new \Bitrix\Main\Web\HttpClient();
    $httpClient->setHeader('Content-Type', 'application/json', true);

    // Проходим по каждому пакету
    for ($packIndex = 0; $packIndex < $packageCount; $packIndex++) {
        // Вычисляем какие ID товаров попадают в текущий пакет
        $offset = $packIndex * $maxPerPackage;
        $packProductIds = array_slice($productIds, $offset, $maxPerPackage);
        $packProductCount = count($packProductIds);

        // Получаем полные данные товаров для этого пакета, сделал в отдельной функцией ниже
        $packProducts = getProductsData($iblockId, $packProductIds);

        // Формируем пакет данных для отправки
        $payload = [
            'iblock_id' => $iblockId,
            'root_section_id' => $rootSectionId,
            'pack' => [
                'index' => $packIndex + 1,
                'total' => $packageCount,
                'product_count' => $packProductCount,
            ],
            'sections' => $sectionsMap,
            'products' => $packProducts,
        ];

        // Отправляем POST-запрос
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $response = $httpClient->post($targetUrl, $jsonPayload);
        if ($httpClient->getStatus() != 200) {
            // В реальном проекте здесь стоит добавить логирование ошибок и возможно повторную отправк
        }
    }

    echo "Экспорт завершён";
}

// Функция получения полных данных по товару, без файлов(лень еще и это делать=D)
function getProductsData($iblockId, $productIds)
{
    $products = [];

    // Получаем основные поля товаров и их свойства
    $elementList = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $productIds],
        false,
        false,
        ['ID', 'NAME', 'CODE', 'DETAIL_TEXT', 'PREVIEW_TEXT',
         'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'IBLOCK_SECTION_ID']
    );
    while ($element = $elementList->Fetch()) {
        $id = $element['ID'];
        $products[$id] = $element;

        // Получаем свойства элемента
        $props = [];
        CIBlockElement::GetPropertyValuesArray(
            $props,
            $iblockId,
            ['ID' => $id],
            ['GET_RAW_DATA' => 'Y']  // получаем сырые значения без HTML форматирования
        );
        $products[$id]['PROPERTIES'] = $props[$id] ?? [];
    }

    // Получаем цены товаров
    // Используем устаревший класс CPrice
    // В реальной работе лучше применять PriceTable::getList
    $priceResult = CPrice::GetList(
        [],
        ['PRODUCT_ID' => $productIds],
        false,
        false,
        ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY']
    );
    $pricesByProduct = [];
    while ($price = $priceResult->Fetch()) {
        $prodId = $price['PRODUCT_ID'];
        unset($price['PRODUCT_ID']);
        $pricesByProduct[$prodId][] = $price;
    }
    foreach ($products as $id => &$product) {
        $product['PRICES'] = $pricesByProduct[$id] ?? [];
    }

    // Получаем данные торгового каталога
    $catalogResult = CCatalogProduct::GetList(
        [],
        ['ID' => $productIds],
        false,
        false,
        ['ID', 'QUANTITY', 'WEIGHT', 'VAT_ID', 'VAT_INCLUDED',
         'CAN_BUY_ZERO', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY']
    );
    $catalogByProduct = [];
    while ($catalog = $catalogResult->Fetch()) {
        $prodId = $catalog['ID'];
        unset($catalog['ID']);
        $catalogByProduct[$prodId] = $catalog;
    }
    foreach ($products as $id => &$product) {
        $product['CATALOG'] = $catalogByProduct[$id] ?? [];
    }

    // Если товар является SKU, получаем его родительский товар
    // Принимающая сторона должна будет восстановить эту связь
    // Для каждого товара проверяем, не является ли он оффером
    $offersParent = [];
    $offerResult = CCatalogSKU::getProductList($productIds, $iblockId);
    if ($offerResult) {
        foreach ($offerResult as $offerId => $parentInfo) {
            $offersParent[$offerId] = $parentInfo['ID'];
        }
    }
    foreach ($products as $id => &$product) {
        $product['PARENT_PRODUCT_ID'] = $offersParent[$id] ?? null;
    }

    return array_values($products);
}
