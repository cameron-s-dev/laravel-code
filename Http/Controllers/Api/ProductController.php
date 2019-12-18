<?php

namespace App\Http\Controllers\Api;

use Validator;
use DB;
use Exception;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use App\Http\Controllers\Controller;
use App\Product;
use App\DynamicImage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $products = Product::all();
        return $products;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function show(Product $product)
    {
        return $product->fullData();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Synchronize products from WordPress
     *
     * @param  \Illuminate\Http\Request     $request
     * @return \Illuminate\Http\Response
     */
    public function syncFromWP(Request $request)
    {
        DB::transaction(function() {
            $client = new Client([
                'base_uri' => env('WP_SITE_URL'),
                'timeout'  => 15,
            ]);

            $client1 = new Client(['timeout' => 15]);
            $productMapResponse = $client1->get(env('PRODUCT_MAP_JSON'));
            $productMapRaw = json_decode((string)$productMapResponse->getBody(), true);
            $productMap = [];
            foreach ($productMapRaw as $key => $value) {
                $productMap[$value] = $key;
            }

            Product::getQuery()->delete();

            $page = 1;
            $per_page = 100;

            while(true) {
                try {
                    $apiResponse = $client->get("/wp-json/wp/v2/product?page=$page&per_page=$per_page");
                } catch (ClientException $e) {
                    if ($e->hasResponse() && $e->getResponse()->getStatusCode() == 400) {
                        break;
                    } else {
                        throw $e;
                    }
                }

                $page++;
                $bodyObj = $apiResponse->getBody();
                $body = (string)$bodyObj;

                $products = json_decode($body, true);
                foreach ($products as $productData) {
                    $post_id = intval($productData['id']);

                    $utmContent = !empty($productMap[$post_id]) ? $productMap[$post_id] : '';

                    $product = new Product();
                    $product->title = $productData['title']['rendered'];
                    $product->image = $productData['product_image'];
                    $product->content = $productData['content']['rendered'];
                    $product->ima_product_id = intval($productData['ima_product_id']);
                    $product->post_id = $post_id;
                    $product->wp_link = $productData['link'];
                    $product->utm_content = $utmContent;
                    $product->save();

                    if ($utmContent) {
                        $dynamicImageCount = DynamicImage::where('utm_content', $utmContent)->count();
                        if (!$dynamicImageCount) {
                            $dynamicImage = new DynamicImage();
                            $dynamicImage->utm_content = $utmContent;
                            $dynamicImage->image = '';
                            $dynamicImage->save();
                        }
                    }
                }
            }
        });

        return response()->json(['success' => true]);
    }
}
