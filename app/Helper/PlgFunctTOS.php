<?php

namespace App\Helper;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Helper\PlgRequestBooking;
use App\Helper\PlgConnectedExternalApps;

class PlgFunctTOS{
	private static function jsonGetTOS($base64){
		return '
			{
			    "repoGetRequest": {
			        "esbHeader": {
			            "internalId": "",
			            "externalId": "",
			            "timestamp": "",
			            "responseTimestamp": "",
			            "responseCode": "",
			            "responseMessage": ""
			        },
			        "esbBody": {
			            "request": "'.$base64.'"
			        },
			        "esbSecurity": {
			            "orgId": "",
			            "batchSourceId": "",
			            "lastUpdateLogin": "",
			            "userId": "",
			            "respId": "",
			            "ledgerId": "",
			            "respAppId": "",
			            "batchSourceName": ""
			        }
			    }
			}
        ';
	}

	private static function decodeResultAftrSendToTosNPKS($res, $type){
		// return $res;
		$res['request']['json'] = json_decode($res['request']['json'], true);
		$res['request']['json'][$type.'Request']['esbBody']['request'] = json_decode(base64_decode($res['request']['json'][$type.'Request']['esbBody']['request']),true);
        $res['response'][$type.'Response']['esbBody']['result'] = json_decode($res['response'][$type.'Response']['esbBody']['result'],true);
        $res['response'][$type.'Response']['esbBody']['result']['result'] = json_decode(base64_decode($res['response'][$type.'Response']['esbBody']['result']['result']),true);
        $res['result'] = $res['response'][$type.'Response']['esbBody']['result']['result'];
        return $res;
	}

	public static function sendRequestBookingPLG($arr){
    	$in_array = ['TX_HDR_REC','TX_HDR_DEL','TX_HDR_STUFF','TX_HDR_STRIPP', 'TX_HDR_FUMI', 'TX_HDR_PLUG'];
    	if (!in_array($arr['config']['head_table'], $in_array)) {
    		$res = [
    			'Success' => false,
    			'note' => 'function bulid json send request, not available!'
    		];
    	}else{
	        $toFunct = 'buildJson'.$arr['config']['head_table'];
	        $json = static::$toFunct($arr);
	        $json = base64_encode(json_encode(json_decode($json,true)));
	        $json = '
				{
				    "repoPostRequest": {
				        "esbHeader": {
				            "internalId": "",
				            "externalId": "",
				            "timestamp": "",
				            "responseTimestamp": "",
				            "responseCode": "",
				            "responseMessage": ""
				        },
				        "esbBody": {
				            "request": "'.$json.'"
				        },
				        "esbSecurity": {
				            "orgId": "",
				            "batchSourceId": "",
				            "lastUpdateLogin": "",
				            "userId": "",
				            "respId": "",
				            "ledgerId": "",
				            "respAppId": "",
				            "batchSourceName": ""
				        }
				    }
				}
	        ';
	        $res = PlgConnectedExternalApps::sendRequestToExtJsonMet([
	        	"user" => config('endpoint.tosPostPLG.user'),
	        	"pass" => config('endpoint.tosPostPLG.pass'),
	        	"target" => config('endpoint.tosPostPLG.target'),
	        	"json" => json_encode(json_decode($json,true))
	        ]);
	        $res = static::decodeResultAftrSendToTosNPKS($res, 'repoPost');
    	}
        return ['sendRequestBookingPLG' => $res];
	}

	public static function getRealPLG($input){
		$config = DB::connection('mdm')->table('TS_NOTA')->where('nota_id', $input['nota_id'])->first();
		$config = json_decode($config->api_set, true);
		$find = DB::connection('omuster')->table($config['head_table'])->where($config['head_primery'],$input['id'])->first();
		$find = (array)$find;
		$dtlLoop = DB::connection('omuster')->table($config['head_tab_detil'])->where($config['head_primery'], $input['id'])->where($config['DTL_IS_ACTIVE'],'Y')->whereIn($config['DTL_FL_REAL'], $config['DTL_FL_REAL_S'])->get();
		$his_cont = [];
		$Success = true;
		$msg = 'Success get realisasion';
		if (count($dtlLoop) > 0) {
			$arr = static::getRealJsonPLG($find,$dtlLoop,$config);
			$res = PlgConnectedExternalApps::sendRequestToExtJsonMet($arr);
			$res = static::decodeResultAftrSendToTosNPKS($res, 'repoGet');
			if ($res['result']['count'] == 0) {
				$Success = false;
				$msg = 'realisasion not finish';
			}else{
				$his_cont = static::storeRealPLG($res,$find,$config,$input);
			}
		}
		$res['his_cont'] = $his_cont;
		$dtl = DB::connection('omuster')->table($config['head_tab_detil']);
		if ($input['nota_id'] == 1) {
			$dtl->leftJoin('TX_GATEIN', function($join) use ($find){
				$join->on('TX_GATEIN.gatein_cont', '=', 'TX_DTL_REC.rec_dtl_cont');
				$join->on('TX_GATEIN.gatein_req_no', '=', DB::raw("'".$find[$config['head_no']]."'"));
			});
		}else if ($input['nota_id'] == 2) {
			$dtl->leftJoin('TX_GATEOUT', function($join) use ($find){
				$join->on('TX_GATEOUT.gateout_cont', '=', 'TX_DTL_DEL.del_dtl_cont');
				$join->on('TX_GATEOUT.gateout_req_no', '=', DB::raw("'".$find[$config['head_no']]."'"));
			});
		}
		$dtl = $dtl->where($config['head_forigen'], $input['id'])->where($config['DTL_IS_ACTIVE'],'Y')->get();
        return [
        	'response' => $Success,
        	'result' => $msg,
        	'no_req' =>$find[$config['head_no']],
        	'hdr' =>$find,
        	'dtl' => $dtl,
        	$config['funct_REAL_GET'] => $res
        ];
	}

	private static function storeRealPLG($data,$hdr,$config,$input){
		$his_cont = [];
		foreach ($data as $listR) {
			$findTsCont = [
				'cont_no' => $listR['NO_CONTAINER'],
				'branch_id' => $hdr[$config['head_branch']],
				'branch_code' => $hdr[$config['head_branch_code']]
			];
			$cekTsCont = DB::connection('omuster')->table('TS_CONTAINER')->where($findTsCont)->first();
			$cont_counter = $cekTsCont->cont_counter; 
			if ($config['kegiatan_real'] == 3) { //kusus gate in
				$cont_counter++;
			}
			$arrStoreTsContAndTxHisCont = [
				'cont_no' => $listR['NO_CONTAINER'],
				'branch_id' => $hdr[$config['head_branch']],
				'branch_code' => $hdr[$config['head_branch_code']],
				'cont_location' => $config['cont_loc_on_real'],
				'cont_size' => null,
				'cont_type' => null,
				'cont_counter' => $cont_counter,
				'no_request' => $listR['NO_REQUEST'],
				'kegiatan' => $config['kegiatan_real'],
				'id_user' => "1",
				'status_cont' => $listR['STATUS'],
				'vvd_id' => $hdr[$config['head_vvd']]
			];
			if (!empty($input["user"])) {
				$arrStoreTsContAndTxHisCont['id_user'] = $input["user"]->user_id;
			}
			static::$config['funct_REAL_STR']($listR,$hdr,$config,$input);
			$upSttDtl = [
				$config['DTL_FL_REAL']=>$config['DTL_FL_REAL_V']
			];
			DB::connection('omuster')->table($config['head_tab_detil'])->where($config['head_forigen'], $hdr[$config['head_primery']])->where($config['DTL_BL'], $listR['NO_CONTAINER'])->update($upSttDtl);
			$his_cont[] = PlgRequestBooking::storeTsContAndTxHisCont($arrStoreTsContAndTxHisCont);
		}
		return $his_cont;
	}

	public static function getRealJsonPLG($find,$dtlLoop,$config){
		$dtl = '';
		$arrdtl = [];
		foreach ($dtlLoop as $list) {
			$list = (array)$list;
			$dtl .= '
			{
				"NO_CONTAINER": "'.$list[$config['DTL_BL']].'",
				"NO_REQUEST": "'.$find[$config['head_no']].'",
				"BRANCH_ID": "'.$find[$config['head_branch']].'"
			},';
		}
		$dtl = substr($dtl, 0,-1);
		$json = '
		{
			"action" : "'.$config['funct_REAL_GET'].'",
			"data": ['.$dtl.']
		}';
		$json = base64_encode(json_encode(json_decode($json,true)));
		$json = static::jsonGetTOS($json);
        $json = json_encode(json_decode($json,true));
		return $arr = [
        	"user" => config('endpoint.tosGetPLG.user'),
        	"pass" => config('endpoint.tosGetPLG.pass'),
        	"target" => config('endpoint.tosGetPLG.target'),
        	"json" => $json
        ];
	}

	public static function storeGATI($listR,$hdr,$config,$input){
		$findGATI = [
			'GATEIN_CONT' => $listR['NO_CONTAINER'],
			'GATEIN_REQ_NO' => $listR['NO_REQUEST'],
			'GATEIN_BRANCH_ID' => $hdr[$config['head_branch']],
			'GATEIN_BRANCH_CODE' => $hdr[$config['head_branch_code']]
		];
		$cek = DB::connection('omuster')->table('TX_GATEIN')->where($findGATI)->first();
		$datenow    = Carbon::now()->format('Y-m-d');
		$storeGATI = [
			"gatein_cont" => $listR['NO_CONTAINER'],
			"gatein_req_no" => $listR['NO_REQUEST'],
			"gatein_pol_no" => $listR['NOPOL'],
			"gatein_cont_status" => $listR['STATUS'],
			"gatein_date" => date('Y-m-d', strtotime($listR['TGL_IN'])),
			"gatein_create_date" => \DB::raw("TO_DATE('".$datenow."', 'YYYY-MM-DD HH24:MI')"),
			"gatein_create_by" => "1",
			"gatein_branch_id" => $hdr[$config['head_branch']],
			"gatein_branch_code" => $hdr[$config['head_branch_code']]
		];
		if (!empty($input["user"])) {
			$storeGATI['gatein_create_by'] = $input["user"]->user_id;
		}
		if (empty($cek)) {
			DB::connection('omuster')->table('TX_GATEIN')->insert($storeGATI);
		}else{
			DB::connection('omuster')->table('TX_GATEIN')->where($findGATI)->update($storeGATI);
		}
	}

	public static function storeGATO($listR,$hdr,$config,$input){
		$findGATO = [
			'GATEOUT_CONT' 					=> $listR['NO_CONTAINER'],
			'GATEOUT_REQ_NO' 				=> $listR['NO_REQUEST'],
			'GATEOUT_BRANCH_ID' 		=> $find[$config['head_branch']],
			'GATEOUT_BRANCH_CODE' 	=> $find[$config['head_branch_code']]
		];

		$cek 		= DB::connection('omuster')->table('TX_GATEOUT')->where($findGATO)->first();
		$datenow    = Carbon::now()->format('Y-m-d');
		$storeGATO  = [
			"gateout_cont" 			 	=> $listR['NO_CONTAINER'],
			"gateout_req_no" 		 	=> $listR['NO_REQUEST'],
			"gateout_pol_no" 		 	=> $listR['NOPOL'],
			"gateout_cont_status" => $listR['STATUS'],
			"gateout_date" 				=> date('Y-m-d', strtotime($listR['TGL_OUT'])),
			"gateout_create_date" => \DB::raw("TO_DATE('".$datenow."', 'YYYY-MM-DD HH24:MI')"),
			"gateout_create_by" 	=> 1,
			"gateout_branch_id" 	=> $hdr[$config['head_branch']],
			"gateout_branch_code" => $hdr[$config['head_branch_code']]
		];

		if (!empty($input["user"])) {
			$storeGATI['gateout_create_by'] = $input["user"]->user_id;
		}

		if (empty($cek)) {
			DB::connection('omuster')->table('TX_GATEOUT')->insert($storeGATO);
		} else {
			DB::connection('omuster')->table('TX_GATEOUT')->where($findGATO)->update($storeGATO);
		}
	}

	public static function storeRealDate($listR,$hdr,$config,$input){
		DB::connection('omuster')->table($config['head_tab_detil'])->where([
			$config['head_forigen'] => $hdr[$config['head_primery']],
			$config['DTL_BL'] => $listR['NO_CONTAINER']
		])->update([
			$config['DTL_REAL_DATE']['uster'] =>date('Y-m-d', strtotime($listR[$config['DTL_REAL_DATE']['tos']])),
			$config['DTL_FL_REAL'] => $config['DTL_FL_REAL_V']
		]);
	}

	public static function getRealPlugPLG($input){
		$his_cont = [];
		$Success = true;
		$msg = 'Success get realisasion';
		$find = DB::connection('omuster')->table('TX_HDR_PLUG')->where('plug_id', $input['plug_id'])->first();
		$dtlLoop = DB::connection('omuster')->table('TX_DTL_PLUG')->where([
			'plug_hdr_id' => $input['plug_id'],
			'plug_dtl_isactive' => 'Y'
		])->whereIn('PLUG_FL_REAL', [1,7])->get();
		if (count($dtlLoop) > 0) {
			$res = static::getPlugInYard($find,$dtlLoop);
			if ($res['result']['count'] > 0) {
				$his_cont = static::storePlugHisCont($res['result']['result'], $find);
			}else{
				$Success = false;
				$msg = 'realisasion not finish';
			}
		}
		$res['his_cont'] = $his_cont;
		$dtl = DB::connection('omuster')->table('TX_DTL_PLUG')->where([
			'plug_hdr_id' => $input['plug_id'],
			'plug_dtl_isactive' => 'Y'
		])->get();
        return [
        	'response' => $Success,
        	'result' => $msg,
        	'no_rec' =>$find->fumi_no,
        	'hdr' =>$find,
        	'dtl' => $dtl,
        	'getRealPlugPLG' => $res
        ];
	}

	public static function getPlugInYard($find,$dtlLoop){
		$dtl = '';
		$arrdtl = [];
		foreach ($dtlLoop as $list) {
			$dtl .= '
			{
				"NO_CONTAINER": "'.$list->fumi_dtl_cont.'",
				"NO_REQUEST": "'.$find->fumi_no.'",
				"BRANCH_ID": "'.$find->fumi_branch_id.'"
			},';
		}
        $dtl = substr($dtl, 0,-1);
		$json = '
		{
			"action" : "generateFumi",
			"data": ['.$dtl.']
		}';
		$json = base64_encode(json_encode(json_decode($json,true)));
		$json = static::jsonGetTOS($json);
        $json = json_encode(json_decode($json,true));
		$arr = [
        	"user" => config('endpoint.tosGetPLG.user'),
        	"pass" => config('endpoint.tosGetPLG.pass'),
        	"target" => config('endpoint.tosGetPLG.target'),
        	"json" => $json
        ];
		$res = static::sendRequestToExtJsonMet($arr);
		return $res = static::decodeResultAftrSendToTosNPKS($res, 'repoGet');
	}

	public static function storePlugHisCont($data,$hdr){
		$his_cont = [];
		foreach ($data as $listR) {
			$findTsCont = [
				'cont_no' => $listR['NO_CONTAINER'],
				'branch_id' => $hdr->fumi_branch_id,
				'branch_code' => $hdr->fumi_branch_code
			];
			$cekTsCont = DB::connection('omuster')->table('TS_CONTAINER')->where($findTsCont)->first();
			$cont_counter = $cekTsCont->cont_counter;
			$cekKegiatan = DB::connection('omuster')->table('TM_REFF')->where([
				"reff_tr_id" => 12,
				"reff_name" => 'REAL FUMIGATION'
			])->first();
			$arrStoreTsContAndTxHisCont = [
				'cont_no' => $listR['NO_CONTAINER'],
				'branch_id' => $hdr->fumi_branch_id,
				'branch_code' => $hdr->fumi_branch_code,
				'cont_location' => 'IN_YARD',
				'cont_size' => null,
				'cont_type' => null,
				'cont_counter' => $cont_counter,
				'no_request' => $listR['NO_REQUEST'],
				'kegiatan' => $cekKegiatan->reff_id,
				'id_user' => "1",
				'status_cont' => $listR['STATUS'],
				'vvd_id' => $hdr->fumi_vvd_id
			];
			if (!empty($input["user"])) {
				$arrStoreTsContAndTxHisCont['id_user'] = $input["user"]->user_id;
			}
			DB::connection('omuster')->table('TX_DTL_FUMI')->where('FUMI_HDR_ID', $hdr->fumi_id)->where('FUMI_DTL_CONT', $listR['NO_CONTAINER'])->update([
				"FUMI_DTL_REAL_DATE"=>date('Y-m-d', strtotime($listR["REAL_FUMI_DATE"])),
				"FUMI_FL_REAL"=>5
			]);
			$his_cont[] = PlgRequestBooking::storeTsContAndTxHisCont($arrStoreTsContAndTxHisCont);
		}
		return $his_cont;
	}

	// store request data to tos
	    private static function buildJsonTX_HDR_REC($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
	          $arrdetil .= '{
	            "REQ_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "REQ_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "REQ_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_NAME']].'",
	            "REQ_DTL_VIA": "'.$dtl[$arr['config']['DTL_VIA_NAME']].'",
	            "REQ_DTL_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "REQ_DTL_TYPE": "'.$dtl[$arr['config']['DTL_CONT_TYPE']].'",
	            "REQ_DTL_CONT_HAZARD": "'.$dtl[$arr['config']['DTL_CHARACTER']].'",
	            "REQ_DTL_OWNER_CODE": "'.$dtl[$arr['config']['DTL_OWNER']].'",
	            "REQ_DTL_OWNER_NAME": "'.$dtl[$arr['config']['DTL_OWNER_NAME']].'"
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;
	        $nota = DB::connection('omuster')->table('TX_HDR_NOTA')->where('nota_req_no', $head[$arr['config']['head_no']])->first();
	        $nota_no = null;
	        $nota_date = null;
	        $nota_paid_date = null;
	        if (!empty($nota)) {
	        	$nota_no = $nota->nota_no;
	        	$nota_date = date('m/d/Y', strtotime($nota->nota_date));
	        	$nota_paid_date = date('m/d/Y', strtotime($nota->nota_paid_date));
	        }
	        $rec_dr = DB::connection('omuster')->table('TM_REFF')->where([
	          'reff_tr_id' => 5,
	          'reff_id' => $head[$arr['config']['head_from']]
	        ])->first();
	        return $json_body = '{
	          "action" : "getReceiving",
	          "header": {
	            "REQ_NO": "'.$head[$arr['config']['head_no']].'",
	            "REQ_RECEIVING_DATE": "'.date('m/d/Y', strtotime($head[$arr['config']['head_date']])).'",
	            "NO_NOTA": "'.$nota_no.'",
	            "TGL_NOTA": "'.$nota_date.'",
	            "NM_CONSIGNEE": "'.$head[$arr['config']['head_cust_name']].'",
	            "ALAMAT": "'.$head[$arr['config']['head_cust_addr']].'",
	            "REQ_MARK": "",
	            "NPWP": "'.$head[$arr['config']['head_cust_npwp']].'",
	            "RECEIVING_DARI": "'.$rec_dr->reff_name.'",
	            "TANGGAL_LUNAS": "'.$nota_paid_date.'",
	            "DI": "",
	            "BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_DEL($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
	          $arrdetil .= '{
	            "REQ_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "REQ_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "REQ_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_NAME']].'",
	            "REQ_DTL_VIA": "'.$dtl[$arr['config']['DTL_VIA_NAME']].'",
	            "REQ_DTL_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "REQ_DTL_TYPE": "'.$dtl[$arr['config']['DTL_CONT_TYPE']].'",
	            "REQ_DTL_CONT_HAZARD": "'.$dtl[$arr['config']['DTL_CHARACTER']].'",
	            "REQ_DTL_DEL_DATE": "'.$dtl[$arr['config']['DEL_DTL_DATE_PLAN']].'",
	            "REQ_DTL_NO_SEAL": ""
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;
					$nota = DB::connection('omuster')->table('TX_HDR_NOTA')->where('nota_req_no', $head[$arr['config']['head_no']])->first();
		      $nota_no = null;
		      $nota_date = null;
		      $nota_paid_date = null;
		      if (!empty($nota)) {
		        $nota_no = $nota->nota_no;
		        $nota_date = date('m/d/Y', strtotime($nota->nota_date));
		        $nota_paid_date = date('m/d/Y', strtotime($nota->nota_paid_date));
		      }
	        $rec_dr = DB::connection('omuster')->table('TM_REFF')->where([
	          'reff_tr_id' => 5,
	          'reff_id' => $head[$arr['config']['head_from']]
	        ])->first();

					$delivery_date = date("m/d/Y", strtotime($head[$arr['config']['head_date']]));

	        return $json_body = '{
	          "action" : "getDelivery",
	          "header": {
	            "REQ_NO": "'.$head[$arr['config']['head_no']].'",
	            "REQ_DELIVERY_DATE": "'.$delivery_date.'",
	            "NO_NOTA": "'.$nota_no.'",
	            "TGL_NOTA": "'.$nota_date.'",
	            "NM_CONSIGNEE": "'.$head[$arr['config']['head_cust_name']].'",
	            "ALAMAT": "'.$head[$arr['config']['head_cust_addr']].'",
	            "REQ_MARK": "",
	            "NPWP": "'.$head[$arr['config']['head_cust_npwp']].'",
	            "DELIVERY_KE": "",
	            "TANGGAL_LUNAS": "'.$nota_paid_date.'",
	            "PERP_DARI": "",
	            "PERP_KE": "'.$rec_dr->reff_name.'",
							"BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_STUFF($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
	          $arrdetil .= '{
	            "REQ_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "REQ_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "REQ_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_NAME']].'",
	            "REQ_DTL_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "REQ_DTL_TYPE": "'.$dtl[$arr['config']['DTL_CONT_TYPE']].'",
	            "REQ_DTL_CONT_HAZARD": "'.$dtl[$arr['config']['DTL_CHARACTER']].'",
	            "REQ_DTL_REMARK_SP2": "",
	            "REQ_DTL_ORIGIN": "'.$dtl[$arr['config']['DTL_CONT_FROM']].'",
	            "TGL_MULAI": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_START_DATE']])).'",
	            "TGL_SELESAI": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_END_DATE']])).'"
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;
	        $nota = DB::connection('omuster')->table('TX_HDR_NOTA')->where('nota_req_no', $head[$arr['config']['head_no']])->first();
	        $nota_no = null;
	        $nota_date = null;
	        $nota_paid_date = null;
	        if (!empty($nota)) {
	        	$nota_no = $nota->nota_no;
	        	$nota_date = date('m/d/Y', strtotime($nota->nota_date));
	        	$nota_paid_date = date('m/d/Y', strtotime($nota->nota_paid_date));
	        }
	        $rec_dr = DB::connection('omuster')->table('TM_REFF')->where([
	          'reff_tr_id' => 5,
	          'reff_id' => $head[$arr['config']['head_from']]
	        ])->first();
	        return $json_body = '{
	          "action" : "getStuffing",
	          "header": {
	            "REQ_NO": "'.$head[$arr['config']['head_no']].'",
	            "REQ_STUFF_DATE": "'.date('m/d/Y', strtotime($head[$arr['config']['head_date']])).'",
	            "NO_NOTA": "'.$nota_no.'",
	            "TGL_NOTA": "'.$nota_date.'",
	            "NM_CONSIGNEE": "'.$head[$arr['config']['head_cust_name']].'",
	            "ALAMAT": "'.$head[$arr['config']['head_cust_addr']].'",
	            "REQ_MARK": "",
	            "NO_UKK": "'.$head[$arr['config']['head_vvd']].'",
	            "NO_BOOKING": "",
	            "NPWP": "'.$head[$arr['config']['head_cust_npwp']].'",
	            "TANGGAL_LUNAS": "'.$nota_paid_date.'",
	            "NO_REQUEST_RECEIVING": "'.$head[$arr['config']['head_rec_no']].'",
	            "STUFFING_DARI": "'.$rec_dr->reff_name.'",
	            "PERP_DARI": "'.$head[$arr['config']['head_ext_from']].'",
	            "PERP_KE": "'.$head[$arr['config']['head_ext_loop']].'",
	            "BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_STRIPP($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
	          $arrdetil .= '{
	            "REQ_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "REQ_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "REQ_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_NAME']].'",
	            "REQ_DTL_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "REQ_DTL_TYPE": "'.$dtl[$arr['config']['DTL_CONT_TYPE']].'",
	            "REQ_DTL_CONT_HAZARD": "'.$dtl[$arr['config']['DTL_CHARACTER']].'",
	            "REQ_DTL_ORIGIN": "'.$dtl[$arr['config']['DTL_CONT_FROM']].'",
	            "TGL_MULAI": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_START_DATE']])).'",
	            "TGL_SELESAI": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_END_DATE']])).'"
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;
	        $nota = DB::connection('omuster')->table('TX_HDR_NOTA')->where('nota_req_no', $head[$arr['config']['head_no']])->first();
	        $nota_no = null;
	        $nota_date = null;
	        $nota_paid_date = null;
	        if (!empty($nota)) {
	        	$nota_no = $nota->nota_no;
	        	$nota_date = date('m/d/Y', strtotime($nota->nota_date));
	        	$nota_paid_date = date('m/d/Y', strtotime($nota->nota_paid_date));
	        }
	        $rec_dr = DB::connection('omuster')->table('TM_REFF')->where([
	          'reff_tr_id' => 5,
	          'reff_id' => $head[$arr['config']['head_from']]
	        ])->first();
	        return $json_body = '{
	          "action" : "getStripping",
	          "header": {
	            "REQ_NO": "'.$head[$arr['config']['head_no']].'",
	            "REQ_STRIP_DATE": "'.date('m/d/Y', strtotime($head[$arr['config']['head_date']])).'",
	            "NO_NOTA": "'.$nota_no.'",
	            "TGL_NOTA": "'.$nota_date.'",
	            "NM_CONSIGNEE": "'.$head[$arr['config']['head_cust_name']].'",
	            "ALAMAT": "'.$head[$arr['config']['head_cust_addr']].'",
	            "REQ_MARK": "",
	            "NPWP": "'.$head[$arr['config']['head_cust_npwp']].'",
	            "DO": "'.$head[$arr['config']['head_do']].'",
	            "BL": "'.$head[$arr['config']['head_bl']].'",
	            "NO_REQUEST_RECEIVING": "'.$head[$arr['config']['head_rec_no']].'",
	            "TANGGAL_LUNAS": "'.$nota_paid_date.'",
	            "STRIP_DARI": "'.$rec_dr->reff_name.'",
	            "PERP_DARI": "'.$head[$arr['config']['head_ext_from']].'",
	            "PERP_KE": "'.$head[$arr['config']['head_ext_loop']].'",
	            "BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_FUMI($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
		        $getCountCounter = DB::connection('omuster')->table('TS_CONTAINER')->where('cont_no',$dtl[$arr['config']['DTL_BL']])->orderBy('cont_counter','desc')->first();
	          $arrdetil .= '{
	            "FUMI_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "FUMI_DTL_CONT_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "FUMI_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "FUMI_DTL_STATUS": "",
	            "FUMI_DTL_CANCELLED": "'.$dtl[$arr['config']['DTL_IS_CANCEL']].'",
	            "FUMI_DTL_ACTIVE": "'.$dtl[$arr['config']['DTL_IS_ACTIVE']].'",
	            "FUMI_DTL_START_FUMI_PLAN": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_ACTIVITY']])).'",
	            "FUMI_DTL_END_FUMI_PLAN": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_ACTIVITY']])).'",
	            "FUMI_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_ID']].'",
	            "FUMI_DTL_COUNTER": "'.$getCountCounter->cont_counter.'"
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;

	        return $json_body = '{
	          "action" : "getFumigasi",
	          "header": {
	          	"FUMI_ID" : "",
	            "FUMI_NO": "'.$head[$arr['config']['head_no']].'",
	            "FUMI_CREATE_BY": "'.$head[$arr['config']['head_by']].'",
	            "FUMI_CREATE_DATE": "'.date('m/d/Y', strtotime($head[$arr['config']['head_date']])).'",
	            "FUMI_CONSIGNEE_ID": "'.$head[$arr['config']['head_cust']].'",
	            "BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_PLUG($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->where($arr['config']['DTL_IS_ACTIVE'],'Y')->get();
	        foreach ($dtls as $dtl) {
	          $dtl = (array)$dtl;
		        $getCountCounter = DB::connection('omuster')->table('TS_CONTAINER')->where('cont_no',$dtl[$arr['config']['DTL_BL']])->orderBy('cont_counter','desc')->first();
	          $arrdetil .= '{
	            "PLUG_DTL_CONT": "'.$dtl[$arr['config']['DTL_BL']].'",
	            "PLUG_DTL_CONT_SIZE": "'.$dtl[$arr['config']['DTL_CONT_SIZE']].'",
	            "PLUG_DTL_CONT_STATUS": "'.$dtl[$arr['config']['DTL_CONT_STATUS']].'",
	            "PLUG_DTL_STATUS": "",
	            "PLUG_DTL_CANCELLED": "'.$dtl[$arr['config']['DTL_IS_CANCEL']].'",
	            "PLUG_DTL_ACTIVE": "'.$dtl[$arr['config']['DTL_IS_ACTIVE']].'",
	            "PLUG_DTL_START_PLUG_PLAN": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_ACTIVITY']])).'",
	            "PLUG_DTL_END_PLUG_PLAN": "'.date('d/m/Y h:i:s', strtotime($dtl[$arr['config']['DTL_DATE_ACTIVITY']])).'",
	            "PLUG_DTL_COMMODITY": "'.$dtl[$arr['config']['DTL_CMDTY_ID']].'",
	            "PLUG_DTL_COUNTER": "'.$getCountCounter->cont_counter.'"
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;

	        return $json_body = '{
	          "action" : "getPlugging",
	          "header": {
	          	"PLUG_ID" : "",
	            "PLUG_NO": "'.$head[$arr['config']['head_no']].'",
	            "PLUG_CREATE_BY": "'.$head[$arr['config']['head_by']].'",
	            "PLUG_CREATE_DATE": "'.date('m/d/Y', strtotime($head[$arr['config']['head_date']])).'",
	            "PLUG_CONSIGNEE_ID": "'.$head[$arr['config']['head_cust']].'",
	            "PLUG_STATUS" : "",
	            "BRANCH_ID" : "'.$head[$arr['config']['head_branch']].'"
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}
	// store request data to tos
}