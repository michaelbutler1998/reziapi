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
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\Json;

use craft\web\twig\variables\Sections;
use craft\elements\Category;

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
    private $_folder;
    public function getBranch($branchId = null)
    {
        // if branch id is supplied then get that specific branch else just return all branches
        // Branches being branches of the company (sub companies)
        if (is_null($branchId)) {
            return ReziApiRecord::find()->all();
        } else {
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
        return json_decode(ReziApiRecord::find()->where(['id' => $branchId])->one()['fieldMapping'], true);
    }
    public function updateBranchMapping($branchId, $mapping, $uniqueIdField)
    {
        $branchModelRecord = $this->getBranch($branchId);
        $branchModelRecord->setAttribute('fieldMapping', $mapping);
        $branchModelRecord->setAttribute('uniqueIdField', $uniqueIdField);
        return $branchModelRecord->save();
    }

    public function getBranchApiKey($branchId)
    {
        return $this->getBranch($branchId)->apiKey;
    }
    public function getFullDetails($propertyId, $branchId)
    {
        $key = $this->getBranchApiKey($branchId);
        
        // A command line tool and library for transferring data with URL syntax,
        // https://github.com/curl/curl
        $curl = curl_init();

        // http://php.net/manual/en/function.curl-setopt.php --> Search for the setting to see what it does
        // curl makes a http request using the propertyID & Key (branch) and rezi returns a json file? 
        
        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.dezrez.com/api/simplepropertyrole/" . $propertyId . "?APIKEY=" . $key,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        //Requesting (not forcing) the server to return Json as it usually returns either json or XML. 
        //For Rezi, this is not required as it only responds with Json regardless, but is good practice.
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
        file_put_contents(__DIR__.'/response.json', $response);
        
        return array(
            'response' => $response,
            'info' => $info,
            'error' => $err
        );
    }
    public function saveEntry($sectionId, $fields, $uniqueIdField, $roleId)
    {
        //$entryType = EntryType::find()->where(['handle' => $handle])->one();
    
        
        $entry = Entry::find()
                ->sectionId($sectionId)
                ->$uniqueIdField($roleId)
                ->status(null)
                ->all();
        // If no entries match the requirements (in correct section with unique ID)
        // Create one, else update the first entry matching this
        if (count($entry) == 0) {
            $entry = new Entry();
            $entry->sectionId = $sectionId;
        } else {
            $entry = $entry[0];
        }

        // $entry->typeId = 1;
        $entry->authorId = 1;
    
        
        // To update entry, if $fields has a matching field then set it as the entries, following this unset the field
        
        if (isset($fields['typeId'])) {
            $entry->typeId = $fields['typeId'];
            unset($fields['typeId']);
        }

        if (isset($fields['title'])) {
            $entry->title = $fields['title'];
            unset($fields['title']);
        }
    
        if (isset($fields['slug'])) {
            $entry->slug = $fields['slug'];
            unset($fields['slug']);
        }
    
        $entry->setFieldValues($fields);
        $entry->enabled = true;

        if (Craft::$app->elements->saveElement($entry)) {
            return $entry;
        } else {
            throw new \Exception("Couldn't save new entry " . print_r($entry->getErrors(), true));
        }
    }

    public function catTest()
    {
        $entry = Entry::find()
        ->sectionId(1)
        ->status(null)
        ->first();

        $categoryGroupId = $entry['parish']->groupId;


        $category = Category::find()
        ->groupId($categoryGroupId)
        ->limit(null)
        ->all();

        $newcat = new Category();
        $newcat->groupId = 2;
        $newcat->title = 'fgdd';

        d($newcat);

        // Craft::$app->elements->saveElement($newcat);

        // d( Craft::$app->categories->saveCat );

        $sections = new Sections();
        $section = $sections->getSectionById(1);
        $entryTypes = $section->getEntryTypes();


        $entryTypeId = $entryTypes[0]->id;

        $fields = $entryTypes[0]->getFieldLayout()->getFields();
        d($entryTypes);
    }
    public function updateCraftCategories($catTitle, int $groupId)
    {
        $category = Category::find()
        ->groupId($groupId)
        ->title($catTitle)
        ->first();
        if ($category === null) {
            $newCategory = new Category();
            $newCategory->groupId = $groupId;
            $newCategory->title = $catTitle;
            if (Craft::$app->elements->saveElement($newCategory)) {
                return $newCategory->id;
            }
        } else {
            return $category->id;
        }
    }
    /**
     * @ TODO
     */
    public function prepareCategory($entryFields, $key, $fieldName)
    {
        $catGroupId = null;
        foreach ($entryFields as $entryField) {
            if ($entryField->handle == $key) {
                // $catGroupId = $entryField->groupId;
                $splitSource = explode(':', $entryField->source);
                if (count($splitSource) == 2) {
                    $catGroupId = $splitSource[1];
                }
            }
        }
        file_put_contents(__DIR__ . '/field.json', json_encode($catGroupId));

        return $catGroupId === null ? [] : [ $this->updateCraftCategories($fieldName, $catGroupId) ];
    }
    public function updateCraftEntry($property, $mapping, $sectionId, $uniqueIdField)
    {
        $mapping = (array) $mapping;
        $property = (array) $property;

        file_put_contents(__DIR__ . '/mapping.json', json_encode($mapping));
        file_put_contents(__DIR__ . '/property.json', json_encode($property));
        
        $sections = new Sections();
        $section = $sections->getSectionById($sectionId);
        $entryTypes = $section->getEntryTypes();
        $entryFields = [];
        $entryTypeId = $entryTypes[0]->id;
        $fields = array(
            'typeId' => $entryTypeId,
        );

        foreach ($entryTypes as $entryType) {
            $entryFields = array_merge($entryFields, $entryType->getFieldLayout()->getFields());
        }
        
        $fields[$uniqueIdField] = $property['RoleId'];
        foreach ($mapping as $key => $map) {
            //check whether we can easily find the maps value on the rezi feed

            switch ($map) {
                case 'RoleType (category)':
                    $fields[$key] = $this->prepareCategory($entryFields, $key, $property['RoleType']['DisplayName']);
                    break;
                case 'Locality (category)':
                    $fields[$key] = $this->prepareCategory($entryFields, $key, $property['Address']['Locality']);
                    break;
                case 'Images':
                    $imageIds = $this->getReziImages($property['Images']);
                    $fields[$key] = $imageIds;
                    break;
                default:
                    if (isset($property[ $map ])) {
                        $fields[$key] = $property[ $map ];
                    } else {
                        $keys = explode('->', $map);
                        
                        $searchedValue = $this->searchReziMultiArray($keys, $property, 0);
                        if ($searchedValue) {
                            $fields[$key] = $searchedValue;
                        }
                    }
            }
        }

        $this->saveEntry($sectionId, $fields, $uniqueIdField, $property['RoleId']);
        // \Kint::dump( $entryTypes );
    }

    public function getReziImages($filesArray)
    {
        $ids = [];

        foreach ($filesArray as $key => $node) {
            // first check if images is already downloaded by its title and if not thn download
            $assets = Asset::Find()
                ->title($node['Id'])
                ->all();
            if (count($assets) > 0) {
                array_push($ids, $assets[0]->id);
            } else {
                $file = $this->file_get_contents_curl($node['Url']);
                $pathinfo = pathinfo($node['Url']);
                
                file_put_contents(__DIR__ . '/fileinfo.txt', $pathinfo);
                $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $pathinfo['basename'];
                FileHelper::writeToFile($path, $file);

                $mimeType = FileHelper::getMimeType($path, null, false);

                if ($mimeType !== null && strpos($mimeType, 'image/') !== 0 && strpos($mimeType, 'application/pdf') !== 0) {
                } else {
                    $asset = new Asset();
                    $asset->tempFilePath = $path;
                    $asset->setScenario(Asset::SCENARIO_CREATE);
                    $asset->filename = $pathinfo['basename'];
                    // $asset->title = $pathinfo['filename'];

                    $asset->avoidFilenameConflicts = true;
                    $asset->setScenario(\craft\elements\Asset::SCENARIO_CREATE);
                    $folder = $this->getFolder(1);

                    $asset->newFolderId = $folder->id;
                    $asset->volumeId = $folder->volumeId;

                    

                    if (!$result = Craft::$app->getElements()->saveElement($asset)) {
                        Craft::error('[API CALLER] Could not store image ' . Json::encode($asset->getErrors()));
                    //\Kint::dump($asset->getErrors());
                    } else {
                        array_push($ids, $asset->id);
                    }
                }

                //$ids.push($asset->id);
            }
        }
        return $ids;
    }

    public function getFolder($id)
    {
        if ($this->_folder === null) {
            $this->_folder = Craft::$app->getAssets()->findFolder(['id' => $id]);
        }
        return $this->_folder;
    }

    public function file_get_contents_curl($url)
    {
        $ch = curl_init();
    
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
        $data = curl_exec($ch);
        curl_close($ch);
    
        return $data;
    }

    public function searchReziMultiArray($keys, $property, $index)
    {
        // i am respresenting multidemensional key like so Address->Postcode
        // so i need to split them in order to traverse the multi dimensional rezi array
        $currentKey = $keys[ $index ];
        if (isset($property[$currentKey])) {
            if (gettype($property[$currentKey]) == 'array') {
                if (!$this->has_string_keys($property[$currentKey])) {
                    $mergedNode = [];
                    foreach ($property[$currentKey] as $node) {
                        $mergedNode = array_merge($mergedNode, $node);
                    }
                    return $this->searchReziMultiArray($keys, $mergedNode, $index+1);
                } else {
                    return $this->searchReziMultiArray($keys, $property[$currentKey], $index+1);
                }
            } else {
                return $property[$currentKey];
            }
        } else {
            return false;
        }


        // if( !isset($property[$key]) ){
        //     return false;
        // }else{
        //     if( gettype($property[$key]) == 'array' ){
        //         foreach( $property[$key] as $propNode ){
        //             return $this->searchReziMultiArray( $key, $propNode );
        //         }
        //     }else{
        //         return $property[$key];
        //     }
        // }
    }
    public function has_string_keys(array $array)
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }

    public function taskTest($branchId, $mapping, $sectionId, $uniqueIdField)
    {
        $propIds = [];
        $props = [];
        $allPropsFound = false;
        $pageNumber = 1;

        while (!$allPropsFound) {
            $pageProps = ReziApi::$plugin->reziApiService->searchReziProperties($pageNumber, $branchId);
            $response = json_decode($pageProps['response'], true);
            
            
            if ($pageProps['info']['http_code'] == 200) {
                if ($response['CurrentCount'] != 0) {
                    foreach ($response['Collection'] as $prop) {
                        if (isset($prop['RoleId'])) {
                            // $propIds [] = $prop['RoleId'];
                            array_push($propIds, $prop['RoleId']);
                        }
                    }
                } else {
                    $allPropsFound = true;
                }
            } else {
                return false;
            }
            $pageNumber++;
        }
        
        foreach ($propIds as $key => $id) {
            $propertyRequest = ReziApi::$plugin->reziApiService->getFullDetails($id, $branchId);
            $response = json_decode($propertyRequest['response'], true);
            if ($propertyRequest['info']['http_code'] == 200) {
                $props [] = $response;
                $updateCraftEntry = ReziApi::$plugin->reziApiService->updateCraftEntry($response, $mapping, $sectionId, $uniqueIdField);
            } else {
                return false;
            }
            // $this->setProgress($queue, $key / count($propIds));
        }
    }
}
