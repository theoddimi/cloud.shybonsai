<?php

namespace Codexdelta\App\Controllers;

use Codexdelta\Libs\HttpApi\ApiHelpers\RequestContentType;
use Codexdelta\Libs\HttpApi\HTTPClient;
use Codexdelta\Libs\HttpApi\HttpHeadersEnum;
use Codexdelta\Libs\HttpApi\Oxygen\OxygenApi;
use Codexdelta\Libs\HttpApi\Woo\WoocommerceApi;
use Codexdelta\Libs\HttpApi\Woo\WoocommerceResourceEndpoint;
use Eckinox\PhpPuppeteer\Browser;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class HomeController
{
    const SKROUTZ_PROFIT_PERCENTAGE = 11.5;
    const PROFIT_PERCENTAGE_THRESHOLD = 20;
    /**
     * @throws Exception
     */
    public function index()
    {
        $productsNotFoundInSkroutzPage = [];
        $skroutzProductsScrapList = config('skroutz_api_mappings', 'ref_eshop');
        $wooUpdateEndpointResults = [];

        // Request products from oxygen
        $oxygenApi = OxygenApi::init();

        $productsOxygenPage = 1;

        do {
            $productsResponse = $oxygenApi->getProducts($productsOxygenPage);
            $productsResponseBody = json_decode($productsResponse->getResponseBody(), true)["data"];
            $countResults = count($productsResponseBody);

            $oxygenProducts[$productsOxygenPage] = $productsResponseBody;
            $productsOxygenPage++;
        } while($countResults !== 0 && $productsOxygenPage<3);
        // END - Request products from oxygen

        foreach ($skroutzProductsScrapList as $product) {
            // Get product from eshop
            $wooApi = WoocommerceApi::initRequest(
                endpoint: WoocommerceResourceEndpoint::RETRIEVE_PRODUCT,
                endpointParameters: [data_get($product, 'eshop_product_id')],
                contentType: RequestContentType::APPLICATION_JSON
            );

            $productEshop = json_decode($wooApi->exec()->getResponseBody(), true);
            $productSku = $productEshop["sku"] ?? null;

            // Get
            $foundProductInOxygen = null;
            $oxygenResultsKey = 1;

            do {
                foreach ($oxygenProducts[$oxygenResultsKey] as $oxygenProduct) {
                    if (isset($oxygenProduct["code"]) && $oxygenProduct["code"] === $productSku) {
                        $foundProductInOxygen = $oxygenProduct;
                        break 2;
                    }
                }

                $oxygenResultsKey++;
            } while($oxygenResultsKey < count($oxygenProducts));

            if (null === $foundProductInOxygen) {
                continue;
            }

//            dump($foundProductInOxygen);

// @TODO Let the flow below run, find the lowest price and before the final decision just use the amounts from oxygen product to find the final profit if we set the value to the minimum

            /** END - Product from eshop and oxygen */

            $urlToScrap = escapeshellarg(data_get($product,'skroutz_page_url'));
            $nodeCommand = $_SERVER['DOCUMENT_ROOT'] . '/resources/js/crawl.cjs ' . $urlToScrap;
            $output = shell_exec('node ' . $nodeCommand);
            $pattern = '/(\d+,\d+)/';

            # Use preg_match to find the first match of the pattern in the input string
            $crawler = new Crawler($output);
            # My shop in skroutz
            $myShopPriceNode = $crawler->filter('li#shop-' . env("SKROUTZ_SHY_BONSAI_SHOP_ID") . ' strong.dominant-price');
            $myShopPriceCount = $myShopPriceNode->count();
            $myShopPrice = null;



            if ($myShopPriceCount > 0) {
                $myShopPrice = $myShopPriceNode->first()->text();

                if (preg_match($pattern, $myShopPrice, $matches)) {
                    // Return the matched number
                    $myShopPrice = floatval(str_replace(',', '.', $matches[1]));
                } else {
                    $myShopPrice = null;
                }
            } else {
                $productsNotFoundInSkroutzPage[] = $foundProductInOxygen;
                // NOTIFY ERROR -> SHOP NOT FOUND IN RESPONSE
            }

            if (!is_numeric($myShopPrice)) {
                // NOTIFY FORMAT ERROR
            }

            # Gather all tags that contain prices and compare with my shop price
            $pricesNodes = $crawler->filter('li:not(#shop-' . env("SKROUTZ_SHY_BONSAI_SHOP_ID") . ') strong.dominant-price');
            $pricesCount = $pricesNodes->count();
            $prices = [];

            if ($pricesCount > 0) {
                foreach ($pricesNodes as $priceNode) {
                    if (is_string($priceNode->textContent)) {
                        if (preg_match($pattern, $priceNode->textContent, $matches)) {
                            // Return the matched number
                            $prices[] = floatval(str_replace(',', '.', $matches[1]));
                        } else {
                            // NOTIFY WRONG REXEXP MATCH FOR PRICE
                        }
                    }
                }
            } else {
                // NOTIFY ERROR -> SHOPS PRICES NOT FOUND IN RESPONSE
            }

            if (count($prices) > 0) {
                ### Compare the results with my shop's price
                $lowestPriceInPage = min($prices);
                if ($lowestPriceInPage < $myShopPrice) {

                    $potentialNewPriceForProduct = $lowestPriceInPage - 0.01;
                    $profitPercentage =
                        $this->calculateProductProfitPercentageForPrice($foundProductInOxygen, $potentialNewPriceForProduct);


                    // Add skroutz profit from new potential price
                    $amountOfSkroutzCommissionForPrice = $this->calculatePercentageResultForValue(
                        self::SKROUTZ_PROFIT_PERCENTAGE,
                        $potentialNewPriceForProduct
                    );

                    $newPriceAfterSkroutzCommissionClearance = $potentialNewPriceForProduct - $amountOfSkroutzCommissionForPrice;
                    $profitPercentageIncludingSkroutzCommission =
                        $this->calculateProductProfitPercentageForPrice(
                            $foundProductInOxygen,
                            $newPriceAfterSkroutzCommissionClearance
                        );

                    // END - Add skroutz profit from new potential price

                    if (true === data_get($product, 'auto_update')) {
                        // Update my price in eshop
                        $wooApi = WoocommerceApi::initRequest(
                            endpoint: WoocommerceResourceEndpoint::UPDATE_PRODUCTS,
                            endpointParameters: [data_get($product, 'eshop_product_id')],
                            requestBody: ['sale_price' => strval($lowestPriceInPage - 0.01)],
                            contentType: RequestContentType::APPLICATION_JSON
                        );

                        $wooUpdateEndpointResults[] = json_decode($wooApi->exec()->getResponseBody(), true)['name'];
                    } else {
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['dry_run'] = true;
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_title'] = data_get($product, 'title');
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_code'] = $foundProductInOxygen["code"];
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_lowest_price_skroutz'] = $lowestPriceInPage;
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_new_price'] = $lowestPriceInPage - 0.01;
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_new_price_percentage_profit'] = $profitPercentage;
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_new_price_percentage_profit_after_commission'] = $profitPercentageIncludingSkroutzCommission;
                        $wooUpdateEndpointResults[$foundProductInOxygen["code"]]['product_page_url'] = data_get($product, 'skroutz_page_url');
                    }
                }
            } else {
                dd('ISSUE WITH READING PRICES FROM MERCHANTS IN SKROUTZ PAGE SCRAPPING');
                // NOTIFY ISSUE WITH READING PRICES FROM MERCHANTS
                exit(1);
            }
        }

        return view('home.twig', ['products_updated' => $wooUpdateEndpointResults]);




        // Http call - NOT WORKING WITH SKROUTZ

//        $skroutzProductsScrap = config('skroutz_api_mappings', 'ref_eshop');
//        $endpointResults = [];
//
//        foreach ($skroutzProductsScrap as $product) {
//            $skroutzProdutPageId = data_get($product,'skroutz_page_product_id');
//
//            $request = HTTPClient::createRequestWithHeaders(
//                "<PROXY_URL>https://www.skroutz.gr/s/$skroutzProdutPageId/filter_products.json",
//                [
//                    new HttpHeadersEnum(HttpHeadersEnum::HEADER_KEY_ACCEPT, HttpHeadersEnum::HEADER_VALUE_ACCEPT_SKROUTZ),
//                    new HttpHeadersEnum(HttpHeadersEnum::HEADER_KEY_ACCEPT_LANGUAGE, HttpHeadersEnum::HEADER_VALUE_ACCEPT_LANGUAGE),
//                ]
//            );
//            $request->addOptionUserAgent(HttpHeadersEnum::HEADER_VALUE_USER_AGENT);
//
//            $response = $request->get();
//            $responseBody = strip_tags($response->getResponseBody());
//
//            $shopProductDetails = json_decode($responseBody, true);
//
//            if (
//                is_array($shopProductDetails) &&
//                count($shopProductDetails) > 0 &&
//                array_key_exists('product_cards', $shopProductDetails)
//            ) {
//                $firstMerchantIdInList = array_key_first($shopProductDetails['product_cards']);
//                $myMerchantProductPrice =
//                    $shopProductDetails['product_cards'][env('SKROUTZ_SHY_BONSAI_MERCHANT_ID')]['raw_price'];
//                $lowestPriceInList = floatval($shopProductDetails['product_cards'][$firstMerchantIdInList]['raw_price']);
//
//                if ($myMerchantProductPrice > $lowestPriceInList) {
//                    // Update my price in eshop
//                    $wooApi = WoocommerceApi::initRequest(
//                        endpoint: WoocommerceResourceEndpoint::UPDATE_PRODUCTS,
//                        endpointParameters: [data_get($product, 'eshop_product_id')],
//                        requestBody: ['sale_price' => strval($lowestPriceInList - 0.01)],
//                        contentType: RequestContentType::APPLICATION_JSON
//                    );
//
//                    $endpointResults[] = json_decode($wooApi->exec()->getResponseBody(), true);
//                } else {
//                    //
//                }
//            }
//        }

        // Send notification with changes
    }

    /**
     * @param array $productProperties
     * @param float $price
     * @return float
     */
    private function calculateProductProfitPercentageForPrice(array $productProperties, float $price): float
    {
        $vatIndicator = round((floatval($productProperties["sale_vat_ratio"])/100), 2) + 1; // 1,24 - 1,06 etc
        $newSaleNetAmount = round(($price/$vatIndicator), 2);// - floatval($oxygenProduct["purchase_total_amount"])

        $netProfit = $newSaleNetAmount - floatval($productProperties["purchase_net_amount"]);

        return round($netProfit*100/floatval($productProperties["purchase_net_amount"]), 2);
    }

    private function calculatePercentageResultForValue(float $percentage, float $value): float
    {
        return round($value*$percentage/100, 2);
    }
}
