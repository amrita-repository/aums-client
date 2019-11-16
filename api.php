<?php
/**
 * MIT License
 *
 * Copyright (c) 2019  RAJKUMAR S
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace AUMS;

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use \GuzzleHttp\Exception\GuzzleException;
use Sunra\PhpSimple\HtmlDomParser;


class API{

    private $username,$password,$session;
    private $client;
    private $baseURI;
    private $studentHashID;
    private $semesterMAP;

    public function __construct($username,$password)
    {
        $this->username=$username;
        $this->password=$password;
        $this->semesterMAP=$this->mapSemester();
        $this->baseURI='https://amritavidya.amrita.edu:8444';
        $this->client=new Client([
            'base_uri' =>$this->baseURI,
            'cookies' => true
        ]);
        $this->session=$this->getSession();
    }

    public function getData(){
        $body=[
            'username' => $this->username,
            'password' => $this->password,
            'lt' => $this->session['lt'],
            '_eventId' => 'submit',
            'submit'   => 'LOGIN'
        ];
        try {
            $this->client->request('POST',$this->session['action'],[
                'form_params' => $body
            ]);
            $response = $this->client->request('GET',"/aums/Jsp/Core_Common/index.jsp?task=off");
            return $this->getUserData($response);
        }catch (GuzzleException $e){
            return json_encode(array(
                'error' => $e->getMessage()
            ));
        }
    }

    public function getUserData($response){

        if($response != null){
             $pageDOM = HtmlDomParser::str_get_html($response->getBody());
             $td = $pageDOM->find('td');
             foreach ($td as $item) {
                 if ($item->width == '70%' && $item->class == 'style3') {
                     $welcomeText = $item->plaintext;
                 }
             }
             if($welcomeText == null){
                 return json_encode(array(
                     'error' => 'Error occurred'
                 ));
             }
             $welcomeText = str_replace('&nbsp;', '', $welcomeText);
             $welcomeText = str_replace('Welcome', '', $welcomeText);
             $welcomeText = str_replace(')', '', $welcomeText);
             $welcomeText = trim($welcomeText);
             $result = explode('(', $welcomeText);
             $name = trim($result[0]);
             $username = strtoupper(trim($result[1]));


            $scripts = $pageDOM -> find('script');
            $count=0;
            foreach ($scripts as $script){
                if($script->language == 'JavaScript')
                {
                    $count++;
                    if($count == 3)
                    $myVar =  explode('myVar = "',$script->plaintext);
                }
            }
            //$this->studentHashID = explode('"',$myVar[1])[0];
            $output = array(
                'username' => $username,
                'info' => $this->getInfo(),
                'grades'=>$this->getGrades(),
            );
            return str_replace('\u00a0','',json_encode($output));

        }else{
            return null;
        }
    }


    public function getInfo(){

        $response = $this->client->request('GET','aums/Jsp/Student/Student.jsp?action=UMS-SRM_INIT_STUDENTPROFILE_SCREEN&isMenu=true');
        $infoDOM = HtmlDomParser::str_get_html($response->getBody());
        $tables = $infoDOM->find('table');
        foreach ($tables as $table){
            if($table->class == 'studInfo'){
                $values = $table->find('td');
            }
        }
        $studentInfo = array();
        for($i=0;$i<sizeof($values);$i+=2){
            $key = trim($values[$i]->plaintext);
            $value = trim($values[$i+1]->plaintext);
            $key = str_replace(':','',$key);
            $key = str_replace('&nbsp;', '', $key);
            $value = str_replace('&nbsp;', '', $value);
            $studentInfo[$key]=$value;
        }
        $studentInfo['CGPA'] = $this->getCGPA();
        return $studentInfo;
    }

    public function getCGPA(){
        $params = [
            'action' => 'UMS-EVAL_STUDPERFORMSURVEY_INIT_SCREEN',
            'isMenu' => 'true'
        ];
        try {
            $response = $this->client->request('GET','/aums/Jsp/StudentGrade/StudentPerformanceWithSurvey.jsp',[
                'query' => $params
            ]);
            if($response->getStatusCode() == 200) {
                $CGPAPage = HtmlDomParser::str_get_html($response->getBody());
                $td= $CGPAPage->find('td');
                foreach ($td as $item) {
                    if ($item->width == '19%' && $item->class == 'rowBG1') {
                        $currentCGPA = $item->plaintext;
                        $currentCGPA = trim($currentCGPA);
                    }
                }
                return $currentCGPA;
            }else{
                return ['error'=>'An error occurred while connecting to server'];
            }
        }catch (GuzzleException $exception){
            die($exception->getMessage());
        }
    }

    public function getSession()
    {
        $query = array(
            'service' => $this->baseURI . '/aums/Jsp/Core_Common/index.jsp'
        );
        try {
            $response = $this->client->request('GET', '/cas/login?' . http_build_query($query));
        }catch (GuzzleException $exception){
            die($exception->getMessage());
        }
        if ($response->getStatusCode() == 200) {
            $loginPage = HtmlDomParser::str_get_html($response->getBody());

            $action = $loginPage->find('#fm1')[0]->action;
            $lt = $loginPage->find('input[name=lt]')[0]->value;
            return array(
                'action' => $action,
                'lt' => $lt
            );
        } else {
            die('Couldn\'t connect to server');
        }
    }


    public function getGrades(){
        $map=$this->mapSemester();
        $grades = array();
        $semester = array(7,8,231,9,10,232,11,12,233,13,14,234,72,73,243,138,139,244,177,190,219);
        $gradeCount=1;
        $body = [
            'htmlPageTopContainer_hiddentblGrades' => '',
            'htmlPageTopContainer_status' => '',
            'htmlPageTopContainer_action' => 'UMS-EVAL_STUDPERFORMSURVEY_CHANGESEM_SCREEN',
            'htmlPageTopContainer_notify'=> ''
        ];
        for($i=0;$i<21;$i++) {
            $body['Page_refIndex_hidden'] = $gradeCount++;
            $body['htmlPageTopContainer_selectStep'] = $semester[$i];
            try {
                $response = $this->client->request('POST', '/aums/Jsp/StudentGrade/StudentPerformanceWithSurvey.jsp?action=UMS-EVAL_STUDPERFORMSURVEY_INIT_SCREEN&isMenu=true&pagePostSerialID=0', [
                    'form_params' => $body
                ]);
                if ($response->getStatusCode() == 200) {
                    try {
                        $gradesDOM = HtmlDomParser::str_get_html($response->getBody());
                        $isPublished = $gradesDOM->find('input[name=htmlPageTopContainer_status]')[0];
                        if ($isPublished->value == 'Result Not Published.') {
                            $thisSem = null;
                        } else {
                            $thisSem = array();
                            $table = $gradesDOM->find('table[width=75%] tbody')[0];
                            $rows = $table->find('tr');
                            for ($j = 1; $j < sizeof($rows); ++$j) {
                                $data = array();
                                $row = $rows[$j];
                                $values = $row->find('td span');
                                if (sizeof($values) > 2) {
                                    $data['CourseCode'] = trim($values[1]->plaintext);
                                    $data['CourseTitle'] = trim($values[2]->plaintext);
                                    $data['Type'] = trim($values[4]->plaintext);
                                    $data['Grade'] = trim($values[5]->plaintext);
                                } else {
                                    $data['SGPA'] = isset($values[1]->plaintext) ?$values[1]->plaintext : "0.0";
                                }
                                array_push($thisSem, $data);
                            }
                            $grades[array_search($semester[$i], $map)] = $thisSem;
                        }
                    } catch (\Exception $exception) {

                    }
                }
            }catch (GuzzleException $exception){
                $grades[array_search($semester[$i], $map)] = null;
            }
        }
        return $grades;
    }


    public function mapSemester(){
        $map = array(
            1 => 7,
            2 => 8,
            'Vacation 1' => 231,
            3 => 9,
            4 => 10,
            'Vacation 2'=>232,
            5 => 11,
            6 => 12,
            'Vacation 3' => 233,
            7 => 13,
            8 => 14,
            'Vacation 4'=>234,
            9 => 72,
            10 =>73,
            'Vacation 5'=>243,
            11=>138,
            12=>139,
            'Vacation 6'=>244,
            13=>177,
            14=>190,
            15=>219
        );

        return $map;

    }
}
