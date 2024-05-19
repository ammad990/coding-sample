<?php

namespace App\Http\Controllers;

use App\Glumen\Foundation\Traits\JobDispatcherTrait;
use App\Glumen\Operations\GetSimilarProductsOperation;
use App\Managers\CatalogManager;
use App\Managers\Variation\MapManager;
use App\Managers\VariationFormatter;
use App\Model\Orm\Item;
use App\Model\Orm\Grade;
use App\Model\Orm\Section;
use App\Model\Orm\Web\HomePageElement;
use App\Model\Orm\Web\HomePageElementChild;
use App\Model\Orm\Variations\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Translator\Translator;
use App\Model\Data\Country;
use Elasticsearch\Endpoints\Sql\Translate;
use Illuminate\Support\Facades\DB;
use App\Helpers\FileHelper;
use App\Model\Orm\Category;
use App\Model\Orm\FunnelLog;
use App\Helpers\VariationsHelper;
use App\Managers\AlgoliaEventManager;
use Illuminate\Support\Facades\App;
use App\Glumen\Domains\Shared\Jobs\GetCartShippingPriceJob;
use App\Glumen\Operations\GetRelatedProductsOperation;

class ProductController extends Controller
{
    use JobDispatcherTrait;
    /**
     * DEPRECATION NOTICE
     * Will be deprecated once we are shifted to country locale urls completely
     * New Function for country locale changes is viewWithCountryLocale()
     *
     * Done by Haseeb Ahmad on 27th June on request of Sajjad bhai and Amr for SEO purpose
     */
    public function view($id, $slug)
    {
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : 0;
        if($queryID != "" && $position != 0){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        $absoluteId = str_replace('id', '', $id);
        $locale     = strtolower(Translator::getInstance()->getLocale());
        $country    = strtolower(Country::getInstance()->getCountry());
        $country    =  $country == 'ksa' ? 'saudi' : $country;

        /** @var Item $model */
        $model = Item::where('id', $absoluteId)
                        ->where('status','publish')
                        ->where('deleted', false)
                        ->firstOrFail();
        if(empty($model->family_number) && empty($model->variation_code)){
            try {
                $variation_code = $model->variation_code;
                $category_id = $model->category_id;
                $unit_price = $model->getConvertedPrice();
                FunnelLog::log(
                    $absoluteId,
                    FunnelLog::LOG_TYPE_VIEW,
                    auth()->user() ? auth()->user()->id : 0,
                    $variation_code,
                    $category_id,
                    $unit_price
                );
            } catch (\Exception $exception) {
            }
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams,301);
        }else{
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams,301);
         }
    }

    public function viewWithCountryLocale($country = 'UAE',$locale = 'en',$id, $slug)
    {
        if($locale){
            setLanguage($locale);
        }
        if($country){
            setCountry($country);
        }
        $absoluteId  = str_replace('id', '', $id);
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : -1;
        if($queryID != "" && $position != -1){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        /** @var Item $model */
        $model = Item::where('id', $absoluteId)
                    ->where('status','publish')
                    ->where('deleted', false)
                    ->firstOrFail();
        if(empty($model->family_number) && empty($model->variation_code)){
            if($queryID != "" && $position != -1){
                $eventManager = new AlgoliaEventManager();
                $eventManager->productClicked($absoluteId, $queryID, $position);
            }
            try {
                $variation_code = $model->variation_code;
                $category_id = $model->category_id;
                $unit_price = $model->getConvertedPrice();
                FunnelLog::log(
                    $absoluteId,
                    FunnelLog::LOG_TYPE_VIEW,
                    auth()->user() ? auth()->user()->id : 0,
                    $variation_code,
                    $category_id,
                    $unit_price
                );
            } catch (\Exception $exception) {
            }

            $brand_id = $model->brand_id ?? ''; // STR-11-Sort-suggested-product-by-brand
            $similar_products = $this->run(GetSimilarProductsOperation::class, [
                'itemID'     => $absoluteId,
                'categoryID' => $model->category_id,
                'optionalParams' => array( // STR-11-Sort-suggested-product-by-brand
                    'priority' => array('sort_column' => 'brand_id', 'priority_val' => $brand_id)
                ),
            ]);

            $grade = $model->condition &&  !empty($model->condition->parentGrade) ?  $model->condition->parentGrade : $model->condition;
            return view('product.view', [
                'model' => $model,
                'services' => $model->getAttachedServices(),
                'grade_description'=>Translator::getInstance()->getLocalizedField($grade->description, $grade->description_ar),
                'similar_products' => $similar_products,
                'queryID' => $queryID
            ]);
        }else{
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
         }
    }

    /**
     * DEPRECATION NOTICE
     * Will be deprecated once we are shifted to country locale urls completely
     * New Function for country locale changes is viewEncodedCountryWise()
     *
     * Done by Haseeb Ahmad on 27th June on request of Sajjad bhai and Amr for SEO purpose
     */

    public function viewEncoded($encoded)
    {
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : 0;
        if($queryID != "" && $position != 0){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        $decoded = base64_decode($encoded);

        if (false === $decoded) {
            throw new ModelNotFoundException();
        }

        @list($id, $stamp) = explode('_', $decoded);

        if (empty($id)) {
            throw new ModelNotFoundException();
        }

        $locale     = strtolower(Translator::getInstance()->getLocale());
        $country    = strtolower(Country::getInstance()->getCountry());
        $country    = $country == 'ksa' ? 'saudi' : $country;
        $absoluteId = $id;
        $model      = null;
        if (strpos($id, "var") !== false) {
            $varId = explode("var", $id);
            if (isset($varId[1]) && $varId[1] != "") {
                $model = Item::getItemByVariation($varId[1]);
                if ($model == null) {
                    throw new ModelNotFoundException();
                }
            }
        }

        if ($model == null) {
            $model = Item::where('id', $id)
            ->where('status','publish')
            ->where('deleted', false)
            ->firstOrFail();
        }

        if(empty($model->family_number) && empty($model->variation_code)){
            try{
                $variation_code = $model->variation_code;
                $category_id = $model->category_id;
                $unit_price = $model->getConvertedPrice();
                FunnelLog::log(
                    $id,
                    FunnelLog::LOG_TYPE_VIEW,
                    auth()->user() ? auth()->user()->id : 0,
                    $variation_code,
                    $category_id,
                    $unit_price
                );
            } catch (\Exception $exception) {
            }
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }else{
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }
    }

    public function viewEncodedCountryWise($country,$lang,$encoded)
    {
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : 0;
        if($queryID != "" && $position != 0){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        $decoded = base64_decode($encoded);

        if (false === $decoded) {
            throw new ModelNotFoundException();
        }

        @list($id, $stamp) = explode('_', $decoded);

        if (empty($id)) {
            throw new ModelNotFoundException();
        }
        $locale     = strtolower($lang);
        if($locale){
            setLanguage($locale);
        }
        if($country){
            setCountry($country);
        }

        $model = null;
        $absoluteId = $id;
        if (strpos($id, "var") !== false) {
            $varId = explode("var", $id);
            if (isset($varId[1]) && $varId[1] != "") {
                $model = Item::getItemByVariation($varId[1]);
                if ($model == null) {
                    throw new ModelNotFoundException();
                }
            }
        }

        if ($model == null) {
            $model = Item::where('id', $id)
            ->where('status','publish')
            ->where('deleted', false)
            ->firstOrFail();
        }
        if(empty($model->family_number) && empty($model->variation_code)){
            try{
                $variation_code = $model->variation_code;
                $category_id = $model->category_id;
                $unit_price = $model->getConvertedPrice();
                FunnelLog::log(
                    $id,
                    FunnelLog::LOG_TYPE_VIEW,
                    auth()->user() ? auth()->user()->id : 0,
                    $variation_code,
                    $category_id,
                    $unit_price
                );
            } catch (\Exception $exception) {
            }
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }else{
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }
    }

    /**
     * DEPRECATION NOTICE
     * Will be deprecated once we are shifted to country locale urls completely
     * New Function for country locale changes is viewVariationWithCountryLocale()
     *
     * Done by Haseeb Ahmad on 27th June on request of Sajjad bhai and Amr for SEO purpose
     */
    public function viewVariation($id, $slug)
    {
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : 0;
        if($queryID != "" && $position != 0){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        $absoluteId = str_replace('id', '', $id);
        $grades=Grade::with('parentGrade')->get();
        /** @var Item $model */
         //Current Product
        $model = Item::with(['user'=>function($query) {
            $query->select('id','username');
        },'shop'=>function($query) {
            $query->select('id','shop_name', 'is_suspended', 'on_vacation', 'seller_status');
        },'productFamily.attributes.parent'])->where('id', $absoluteId)
        ->where('status','publish')
        ->where('deleted', false)
        ->firstOrFail();

        if (!$model) {
            abort(404);
        }


        $locale     = strtolower(Translator::getInstance()->getLocale());
        $country    = strtolower(Country::getInstance()->getCountry());
        $country    =  $country == 'ksa' ? 'saudi' : $country;

        if(empty($model->family_number) && empty($model->variation_code)){
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }

        $family_obj = $this->makefamilyobject($model);

        $grade_meta=[];
        foreach($grades as $key =>$value){
            $parent_grade = null;
            if(isset($value->parentGrade) && !empty($value->parentGrade)){
                $parent = $value->parentGrade;
                $parent_grade  = array(
                    'grade'=>$parent->grade,
                    'description'=>Translator::getInstance()->getLocalizedField($parent->description, $parent->description_ar),
                    'cond'=>$parent->cond,
                    'parent'=>null
                );
            }
            $grade_meta[$value->grade_ar] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'           =>  $value->getGradeIcon(),
                'desc_image_url' =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for arabic variation
            $grade_meta[$value->grade_code] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'              =>  $value->getGradeIcon(),
                'desc_image_url'    =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for english variation
        }

        try{
            $variation_code = $model->variation_code;
            $category_id = $model->category_id;
            $unit_price = $model->getConvertedPrice();
            FunnelLog::log(
                $absoluteId,
                FunnelLog::LOG_TYPE_VIEW,
                auth()->user() ? auth()->user()->id : 0,
                $variation_code,
                $category_id,
                $unit_price
            );
        } catch (\Exception $exception) {
        }
        return view('product.variationview', [
            'model' => $model,
            'services' => $model->getAttachedServices(),
            'product_family'=>$family_obj,
            'grades'=>$grades,
            'grade_meta'=>$grade_meta,
            'grade_description'=> $model->getGradeLocalizedDescription(),
            'queryID' => $queryID
        ]);
    }

    public function viewVariationWithCountryLocale($country='UAE',$locale = 'en',$id, $slug)
    {
        $absoluteId  = str_replace('id', '', $id);
        $queryParams = "";
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : -1;
        if($queryID != "" && $position != -1){
            $queryParams = "?queryID={$queryID}&position={$position}";
        }
        $grades=Grade::with('parentGrade')->get();
        /** @var Item $model */
         //Current Product
        $model = Item::with(['user'=>function($query) {
            $query->select('id','username');
        },'shop'=>function($query) {
            $query->select('id','shop_name', 'is_suspended', 'on_vacation', 'seller_status');
        },'productFamily.attributes.parent'])->where('id', $absoluteId)
            ->where('status','publish')
            ->where('deleted', false)
            ->firstOrFail();

        if (!$model) {
            abort(404);
        }
        return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        if(empty($model->family_number) && empty($model->variation_code)){
            return redirect("/$country/$locale/pdp/id{$model->id}/" . $model->item_title_url.'.html'.$queryParams);
        }
        if($queryID != "" && $position != -1){
            $eventManager = new AlgoliaEventManager();
            $eventManager->productClicked($absoluteId, $queryID, $position);
        }
        $locale     = strtolower($locale);
        if($locale){
            setLanguage($locale);
        }
        if($country){
            setCountry($country);
        }
        $family_obj = $this->makefamilyobject($model);

        $grade_meta=[];
        foreach($grades as $key =>$value){
            $parent_grade = null;
            if(isset($value->parentGrade) && !empty($value->parentGrade)){
                $parent = $value->parentGrade;
                $parent_grade  = array(
                    'grade'=>$parent->grade,
                    'description'=>Translator::getInstance()->getLocalizedField($parent->description, $parent->description_ar),
                    'cond'=>$parent->cond,
                    'parent'=>null
                );
            }
            $grade_meta[$value->grade_ar] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'           =>  $value->getGradeIcon(),
                'desc_image_url' =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for arabic variation
            $grade_meta[$value->grade_code] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'              =>  $value->getGradeIcon(),
                'desc_image_url'    =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for english variation
        }
        try{
            $variation_code = $model->variation_code;
            $category_id = $model->category_id;
            $unit_price = $model->getConvertedPrice();
            FunnelLog::log(
                $absoluteId,
                FunnelLog::LOG_TYPE_VIEW,
                auth()->user() ? auth()->user()->id : 0,
                $variation_code,
                $category_id,
                $unit_price
            );
        } catch (\Exception $exception) {
        }

        $brand_id = $model->brand_id ?? ''; // STR-11-Sort-suggested-product-by-brand
        $similar_products = $this->run(GetSimilarProductsOperation::class, [
            'itemID'     => $absoluteId,
            'categoryID' => $model->category_id,
            'optionalParams' => array( // STR-11-Sort-suggested-product-by-brand
                'priority' => array('sort_column' => 'brand_id', 'priority_val' => $brand_id)
            ),
        ]);
        return view('product.variationview', [
            'model' => $model,
            'services' => $model->getAttachedServices(),
            'product_family'=>$family_obj,
            'grades'=>$grades,
            'grade_meta'=>$grade_meta,
            'grade_description'=> $model->getGradeLocalizedDescription(),
            'similar_products' => $similar_products,
            'queryID' => $queryID
        ]);
    }


    public function getVariationData($id)
    {
        $model = Product::where('id', $id)->firstOrFail();

        $data = VariationFormatter::getInstance()->setProduct($model)->format();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
    private function makefamilyobject($model)
    {
        return VariationsHelper::getItemVariationsForWeb($model);
    }

    public function selectItem(Request $request)
    {
        $ids = $request->all();
        $condition= "";
        $model = Item::where('id', $ids['itemId'])
            ->where('deleted', false)
            ->select('item_title','brand_id','grade','price','category_id','id')
            ->firstOrFail();
        if(isset($ids['element_id'])){
            $condition = HomePageElement::where('id',$ids['element_id'])->pluck('title')->toArray();
            $condition = strtolower(implode("",$condition));
        }
        if(isset($ids['category_id'])){
            $condition = "product listing page";
        }
        if(isset($ids['section_id'])){
            $condition = DB::table('home_page_element_children as hpec')
                        ->leftjoin('home_page_elements as hpe', 'hpec.element_id', '=', 'hpe.id')
                        ->where('hpec.item_id','=',$ids['section_id'])
                        ->pluck('hpe.title')
                        ->toArray();
            $condition = strtolower(implode("",$condition));
        }
        $itemDataList = [];
        $response = [];
        if($model){
            $itemDataList = [
                'item_name' => strtolower($model->item_title),
                'item_brand'=> isset($model->brand) ? $model->brand->id."_".strtolower($model->brand->name) : '',
                'item_variant' => strtolower($model->getGradeLocalizedLabel()),
                'item_category'=> isset($model->category) ? $model->category->id."_".strtolower($model->category->category_name) : '',
                'price' => $model->getConvertedPrice(),
                'item_id' => $model->id,
            ];
            $response = [
                'status' => true,
                'eventData' => $itemDataList,
                'condition'=> $condition
            ];
            return response()->json($response);
        }
        $response = [
            'status' => false,
            'eventData' => $itemDataList
        ];
        return response()->json($response);
    }

    public function viewProduct($country='UAE',$locale = 'en',$id, $slug)
    {
        $locale     = strtolower($locale);
        if($locale){
            setLanguage($locale);
        }
        if($country){
            setCountry($country);
        }
        $absoluteId = str_replace('id', '', $id);
        $queryID     = (request()->has('queryID')) ? request()->input('queryID') : "";
        $position    = (request()->has('position')) ? request()->input('position') : -1;
        if($queryID != "" && $position != -1){
            $eventManager = new AlgoliaEventManager();
            $eventManager->productClicked($absoluteId, $queryID, $position);
        }
        $grades=Grade::with('parentGrade')->get();
        /** @var Item $model */
         //Current Product
        $shippingPrice = $this->getCartShippingPrice(0, 0);
        $shippingPrice = number_format($shippingPrice,2);
        $model = Item::with(['user'=>function($query) {
            $query->select('id','username');
        },'shop'=>function($query) {
            $query->select('id','shop_name','user_id','merchant_name', 'seller_status', 'country_id', 'store_enable', 'cod_charges', 'in_house', 'on_vacation', 'is_suspended', 'is_rls_seller');
        },'productFamily.attributes.parent'])->where('id', $absoluteId)
            // ->where('status','publish')
            ->where('deleted', false)
            ->firstOrFail();

        if (!$model) {
            abort(404);
        }
        $priceDropData[$model->id] = ["status" => false, "title" => ""];
        if(empty($model->family_number) && empty($model->variation_code)){
            try {
                $variation_code = $model->variation_code;
                $category_id = $model->category_id;
                $unit_price = $model->getConvertedPrice();
                FunnelLog::log(
                    $absoluteId,
                    FunnelLog::LOG_TYPE_VIEW,
                    auth()->user() ? auth()->user()->id : 0,
                    $variation_code,
                    $category_id,
                    $unit_price
                );
            } catch (\Exception $exception) {
            }

            $brand_id = $model->brand_id ?? ''; // STR-11-Sort-suggested-product-by-brand
            $similar_products = $this->run(GetSimilarProductsOperation::class, [
                'itemID'     => $absoluteId,
                'categoryID' => $model->category_id,
                'optionalParams' => array( // STR-11-Sort-suggested-product-by-brand
                    'priority' => array('sort_column' => 'brand_id', 'priority_val' => $brand_id), 'limit' => 50
                ),
            ]);
            $related_products = $this->run(GetRelatedProductsOperation::class, [
                'itemID'         => $absoluteId,
                'optionalParams' => [
                    'limit' => 10
                ],
            ]);

            $grade = $model->condition &&  !empty($model->condition->parentGrade) ?  $model->condition->parentGrade : $model->condition;

            $priceChanges  = DB::table("price_changes")
                ->whereRAW("changedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND changedAt <= NOW()")
                ->whereRAW("before_price > 0 AND after_price > 0")
                ->where("item_id", $model->id)
                ->orderBy('id','DESC')
                ->first();
            if ($priceChanges && isset($priceChanges->id) && $priceChanges->before_price > 0 && $priceChanges->after_price > 0 && $priceChanges->before_price > $priceChanges->after_price) {
                $percent_dropped          = (($priceChanges->before_price-$priceChanges->after_price)/$priceChanges->before_price)*100;
                $percent_dropped          = round($percent_dropped, 0, PHP_ROUND_HALF_UP);
                $before_price             = number_format(round($priceChanges->before_price, 0, PHP_ROUND_HALF_UP), 2, '.', '');
                $priceDrop                = __('item_detail.price_drop');
                $priceDrop                = str_replace(":percent_dropped", $percent_dropped, $priceDrop);
                $priceDrop                = str_replace(":before_price", $before_price, $priceDrop);
                if ($percent_dropped >= 1 && $model->getQuantity() > 0) {
                    $priceDropData[$model->id] = [
                        "status" => true,
                        "title"  => $priceDrop
                    ];
                }
            }
            return view('product.view', [
                'model' => $model,
                'services' => $model->getAttachedServices(),
                'grade_description'=>Translator::getInstance()->getLocalizedField($grade->description, $grade->description_ar),
                'grade_data' => $grade,
                'similar_products' => $similar_products,
                'related_products' => $related_products,
                'queryID' => $queryID,
                'shippingPrice'=>$shippingPrice,
                'priceDropData' => $priceDropData
            ]);
        }
        $family_obj = $this->makefamilyobject($model);
        $countries  = [];
        $itemIds = [];
        if($model->getQuantity() > 0) {
            $itemIds[$model->id]   = $model->id;
            $countries[$model->id] = (isset($model->countryid)) ? $model->countryid : 1;
        }
        if(isset($family_obj['variations_items']) && count($family_obj['variations_items']) > 0) {
            foreach($family_obj['variations_items'] as $varKey => $item) {
                if ($item['quantity'] > 0) {
                    $itemIds[$item['item_asc_id']]   = $item['item_asc_id'];
                    $countries[$item['item_asc_id']] = (isset($item['shop']) && isset($item['shop']['country_id'])) ? $item['shop']['country_id'] : 1;
                }
            }
        }
        $priceChangedItems = [];
        if (count($itemIds) > 0) {
            $priceChanged = DB::SELECT("SELECT
                    pc.*
                FROM
                    price_changes pc
                JOIN (
                    SELECT
                        item_id,
                        MAX(changedAt) AS max_changedAt
                    FROM
                        price_changes
                    WHERE
                        before_price > 0
                        AND after_price > 0
                        AND item_id IN (
                            " . implode(",", $itemIds) . "
                        )
                        AND changedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        AND changedAt <= NOW()
                    GROUP BY
                        item_id
                ) latest_changes
                ON pc.item_id = latest_changes.item_id AND pc.changedAt = latest_changes.max_changedAt;
            ");
            if (count($priceChanged)> 0) {
                foreach ($priceChanged as $item) {
                    $priceChangedItems[$item->item_id] = (array) $item;
                }
            }
        }
        foreach($priceChangedItems as $priceChanges) {
            if ($priceChanges && isset($priceChanges['id']) && $priceChanges['before_price'] > 0 && $priceChanges['after_price'] > 0 && $priceChanges['before_price'] > $priceChanges['after_price']) {
                $percent_dropped          = (($priceChanges['before_price']-$priceChanges['after_price'])/$priceChanges['before_price'])*100;
                $percent_dropped          = round($percent_dropped, 0, PHP_ROUND_HALF_UP);
                $convertFrom              = (isset($countries[$priceChanges['item_id']])) ? $countries[$priceChanges['item_id']] : 1;
                $before_price             = Country::getInstance()->convertPrice(number_format(round($priceChanges['before_price'], 0, PHP_ROUND_HALF_UP), 2, '.', ''), $convertFrom);
                $priceDrop                = __('item_detail.price_drop');
                $priceDrop                = str_replace(":percent_dropped", $percent_dropped, $priceDrop);
                $priceDrop                = str_replace(":before_price", $before_price, $priceDrop);
                if ($percent_dropped >= 1) {
                    $priceDropData[$priceChanges['item_id']] = [
                        "status" => true,
                        "title"  => $priceDrop
                    ];
                }
            }
        }

        $grade_meta=[];
        foreach($grades as $key =>$value){
            $parent_grade = null;
            if(isset($value->parentGrade) && !empty($value->parentGrade)){
                $parent = $value->parentGrade;
                $parent_grade  = array(
                    'grade'=>$parent->grade,
                    'description'=>Translator::getInstance()->getLocalizedField($parent->description, $parent->description_ar),
                    'cond'=>$parent->cond,
                    'parent'=>null
                );
            }
            $grade_meta[$value->grade_ar] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'           =>  $value->getGradeIcon(),
                'desc_image_url' =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for arabic variation
            $grade_meta[$value->grade_code] = [
                'grade'=>Translator::getInstance()->getLocalizedField($value->grade, $value->grade_ar),
                'description'=>Translator::getInstance()->getLocalizedField($value->description, $value->description_ar),
                'cond'=>Translator::getInstance()->getLocalizedField($value->cond, $value->cond_ar),
                'code'  =>  $value->grade_code,
                'color' =>  $value->color,
                'icon'              =>  $value->getGradeIcon(),
                'desc_image_url'    =>  $value->getGradeDescriptionImage(),
                'desc_image_url_warranty' =>  $value->getGradeDescriptionImageWarranty(),
                'parent'=>$parent_grade
            ]; // for english variation
        }
        try{
            $variation_code = $model->variation_code;
            $category_id = $model->category_id;
            $unit_price = $model->getConvertedPrice();
            FunnelLog::log(
                $absoluteId,
                FunnelLog::LOG_TYPE_VIEW,
                auth()->user() ? auth()->user()->id : 0,
                $variation_code,
                $category_id,
                $unit_price
            );
        } catch (\Exception $exception) {
        }

        $brand_id = $model->brand_id ?? ''; // STR-11-Sort-suggested-product-by-brand
        $similar_products = $this->run(GetSimilarProductsOperation::class, [
            'itemID'     => $absoluteId,
            'categoryID' => $model->category_id,
            'optionalParams' => array( // STR-11-Sort-suggested-product-by-brand
                'priority' => array('sort_column' => 'brand_id', 'priority_val' => $brand_id)
            ),
        ]);
        $related_products = $this->run(GetRelatedProductsOperation::class, [
            'itemID'         => $absoluteId,
            'optionalParams' => [
                'limit' => 10
            ],
        ]);
        return view('product.view', [
            'model' => $model,
            'services' => $model->getAttachedServices(),
            'product_family'=>$family_obj,
            'grades'=>$grades,
            'grade_meta'=>$grade_meta,
            'grade_description'=> $model->getGradeLocalizedDescription(),
            'similar_products' => $similar_products,
            'related_products' => $related_products,
            'queryID' => $queryID,
            'shippingPrice'=>$shippingPrice,
            'priceDropData' => $priceDropData
        ]);
    }

    public function getCartShippingPrice($userID,$shippingAddress)
    {
        return $this->run(GetCartShippingPriceJob::class, [
            'userID'          => $userID,
            'shippingAddress' => $shippingAddress,
        ]);
    }
}
