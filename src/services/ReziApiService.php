<?php
/**
 * Rezi API plugin for Craft CMS 3.x
 *
 * An integration with dezrez cloud based estate agency software
 *
 * @link      https://github.com/Jegard
 * @copyright Copyright (c) 2018 Luca Jegard
 */

namespace lucajegard\reziapi\services;

use lucajegard\reziapi\ReziApi;

use Craft;
use craft\base\Component;
//use lucajegard\reziapi\models\RezApiModel;
use lucajegard\reziapi\records\ReziApiRecord;
use craft\elements\Entry;

use craft\web\twig\variables\Sections;

/**
 * ReziApiService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Luca Jegard
 * @package   ReziApi
 * @since     1.0.0
 */
class ReziApiService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     ReziApi::$plugin->reziApiService->exampleService()
     *
     * @return mixed
     */
    public function getBranch($branchId = null)
    {
        // if branch id is supplied then get that specific branch else just return all branches
        if(is_null($branchId)){
            return ReziApiRecord::find()->all();
        }else{
            return ReziApiRecord::find()->where(['id' => $branchId])->one();
        }
    }
    
    public function createBranchModel($branchName, $apiKey, $sectionId)
    {
        $branchModelRecord = new ReziApiRecord;
        $branchModelRecord->setAttribute('branchName', $branchName);
        $branchModelRecord->setAttribute('apiKey', $apiKey);
        $branchModelRecord->setAttribute('sectionId', $sectionId);
        return $branchModelRecord->save();
    }

    public function deleteBranchModel($id)
    {
        return ReziApiRecord::find()->where(['id' => $id])->one()->delete();
    }
    public function getBranchMapping($branchId)
    {
        return json_decode( ReziApiRecord::find()->where(['id' => $branchId])->one()['fieldMapping'], true );
    }
    public function updateBranchMapping($branchId, $mapping)
    {
        $branchModelRecord = $this->getBranch( $branchId );
        $branchModelRecord->setAttribute('fieldMapping', $mapping);
        return $branchModelRecord->save();
    }

    public function getBranchApiKey($branchId)
    {
        return $this->getBranch( $branchId )->apiKey;
    }
    public function getFullDetails($propertyId, $branchId)
    {
        $key = $this->getBranchApiKey($branchId);
        
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.dezrez.com/api/simplepropertyrole/" . $propertyId . "?APIKEY=" . $key,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
            "Cache-Control: no-cache",
            "Content-Type: application/json",
            "Postman-Token: cd473410-1407-20c3-ca9e-6a389f713df9",
            "Rezi-Api-Version: 1.0"
        ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $info = curl_getinfo($curl);

        curl_close($curl);

        return array(
            'response' => $response,
            'info' => $info,
            'error' => $err
        );
    }
    public function searchReziProperties($pageNumber = 1, $branchId)
    {
        $curl = curl_init();
        $key = $this->getBranchApiKey($branchId);
        file_put_contents(__DIR__ . '/key.json', $key);
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.dezrez.com/api/simplepropertyrole/search?APIKey=" . $key,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => "{\n  MinimumPrice: 0,\n  MaximumPrice:1000000,\n  MinimumBedrooms:0,\n  MaximumBedrooms:10,\n  BranchIdList:[],\n  PageNumber: ".$pageNumber.",\n PageSize: 20,\n MarketingFlags: ['ApprovedForMarketingWebsite'] }",
        CURLOPT_HTTPHEADER => array(
            "Cache-Control: no-cache",
            "Content-Type: application/json",
            "Postman-Token: 3db62e40-65ed-79c5-ca27-5f0681bf2e6b",
            "Rezi-Api-Version: 1.0"
        ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $info = curl_getinfo($curl);

        curl_close($curl);

        
        return array(
            'response' => $response,
            'info' => $info,
            'error' => $err
        );
    }
    public function saveNewEntry( $sectionId, $fields)
    {
        //$entryType = EntryType::find()->where(['handle' => $handle])->one();
    
        $entry = new Entry();
        $entry->sectionId = $sectionId;

        // $entry->typeId = 1;
        $entry->authorId = 1;
    
        if(isset($fields['typeId'])) {
            $entry->typeId = $fields['typeId'];
            unset($fields['typeId']);
        }

        if(isset($fields['title'])) {
            $entry->title = $fields['title'];
            unset($fields['title']);
        }
    
        if(isset($fields['slug'])) {
            $entry->slug = $fields['slug'];
            unset($fields['slug']);
        }
    
        $entry->setFieldValues($fields);
    
        if(Craft::$app->elements->saveElement($entry)) {
            return $entry;
        } else {
            throw new \Exception("Couldn't save new entry " . print_r($entry->getErrors(), true));
        }
    }
    public function updateCraftEntry($property, $mapping, $sectionId)
    {
        $mapping = (array) $mapping;
        $property = (array) $property;

        file_put_contents( __DIR__ . '/mapping.json' , json_encode($mapping) );
        file_put_contents( __DIR__ . '/property.json' , json_encode($property ) );
        $sections = new Sections();
        $section = $sections->getSectionById($sectionId);
        $entryTypes = $section->getEntryTypes();
        $entryTypeId = $entryTypes[0]->id;

        $fields = $entryTypes[0]->getFieldLayout()->getFields();
        
        // \Kint::dump( $field );

        $fields = array(
            'title' => 'title yo 2',
            'typeId' => $entryTypeId
        );

        foreach($mapping as $key => $map){
            //check whether we can easily find the maps value on the rezi feed
            if( isset( $property[ $map ] ) ){
                $fields[$key] = $property[ $map ];
            }elseif( $this->findPropertyValueByMap($map, $property) != false ){
                $fields[$key] = $this->findPropertyValueByMap($map, $property);
            }
        }

        $this->saveNewEntry( $sectionId, $fields );
        // \Kint::dump( $entryTypes );
    }
    public function findPropertyValueByMap($map, $array){
        $map = explode('->', $map);
        $currentValue = $array;

        if(count($map) > 1){
            foreach($map as $key){

                if( gettype($key) == 'array' ){
                    return false;
                }
                elseif( isset( $currentValue[$key] ) ){
                    $currentValue = $currentValue[$key];
                }
            }
            return gettype($currentValue) == 'array' ? false : $currentValue;
        }else{
            return false;
        }
    }

















    public function taskTest( $branchId, $mapping, $sectionId ){
        $propIds = [];
        $props = [];
        $allPropsFound = false;
        $pageNumber = 1;

        while(!$allPropsFound){
            $pageProps = ReziApi::$plugin->reziApiService->searchReziProperties($pageNumber, $branchId);
            $response = json_decode($pageProps['response'], true);
            
            
            if($pageProps['info']['http_code'] == 200){
                if($response['CurrentCount'] != 0){
                    
                    foreach( $response['Collection'] as $prop ){
                        
                        if( isset($prop['RoleId']) ){
                            // $propIds [] = $prop['RoleId'];
                            array_push( $propIds, $prop['RoleId'] );
                        }
                    }
                }else{
                    $allPropsFound = true;
                }
            }else{
                return false;
            }
            $pageNumber++;
        }
        
        foreach($propIds as $key => $id){
            $propertyRequest = ReziApi::$plugin->reziApiService->getFullDetails($id, $branchId);
            $response = json_decode($propertyRequest['response'], true);
            if($propertyRequest['info']['http_code'] == 200){
                $props [] = $response;
                $updateCraftEntry = ReziApi::$plugin->reziApiService->updateCraftEntry($response, $mapping, $sectionId);
            }else{
                return false;
            }

            // $this->setProgress($queue, $key / count($propIds));
        }
    }
}
