<?php

namespace App\Helper;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\OmUster\TxHdrNota;
use App\Helper\PlgRequestBooking;

class PlgConnectedExternalApps{
	// PLG
		public static function getVesselNpks($input){
			$json = '
			{
				"getVesselNpksRequest": {
					"esbHeader": {
						"internalId": "",
			        	"externalId": "",
			        	"timestamp": "",
			        	"responseTimestamp": "",
			        	"responseCode": "",
			        	"responseMessage": ""
						},
						"esbBody":   {
							"vessel":"'.$input['query'].'"
							},
						"esbSecurity": {
							"orgId":"",
							"batchSourceId":"",
							"lastUpdateLogin":"",
							"userId":"",
							"respId":"",
							"ledgerId":"",
							"respApplId":"",
							"batchSourceName":"",
							"category":""
						}
					}
			}';
			$json = json_encode(json_decode($json,true));
			$res = static::sendRequestToExtJsonMet([
	        	"user" => config('endpoint.esbGetVesselNpks.user'),
	        	"pass" => config('endpoint.esbGetVesselNpks.pass'), 
	        	"target" => config('endpoint.esbGetVesselNpks.target'), 
	        	"json" => $json
	        ]);
			return $res;
		}

		private static function decodeResultAftrSendToTosNPKS($res){
			$res['request'] = json_decode($res['request']['json'], true);
	        $res['request'] = json_decode(base64_decode($res['request']['request']),true);
	        $res['response'] = json_decode(base64_decode($res['response']['result']),true);
	        return $res;
		}

	    public static function sendRequestBookingPLG($arr){
	        $toFunct = 'buildJson'.$arr['config']['head_table'];
	        $res = static::sendRequestToExtJsonMet([
	        	"user" => config('endpoint.tosPostPLG.user'),
	        	"pass" => config('endpoint.tosPostPLG.pass'), 
	        	"target" => config('endpoint.tosPostPLG.target'), 
	        	"json" => '{ "request" : "'.base64_encode(json_encode(json_decode(static::$toFunct($arr),true))).'"}'
	        ]);
	        $res = static::decodeResultAftrSendToTosNPKS($res);
	        return ['sendRequestBookingPLG' => $res];
		}

	    private static function buildJsonTX_HDR_REC($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->get();
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
	            "DI": ""
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		private static function buildJsonTX_HDR_DEL($arr){
	        $arrdetil = '';
	        $dtls = DB::connection('omuster')->table($arr['config']['head_tab_detil'])->where($arr['config']['head_forigen'], $arr['id'])->get();
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
	            "REQ_DTL_DEL_DATE": "",
	            "REQ_DTL_NO_SEAL": ""
	          },';
	        }
	        $arrdetil = substr($arrdetil, 0,-1);
	        $head = DB::connection('omuster')->table($arr['config']['head_table'])->where($arr['config']['head_primery'], $arr['id'])->first();
	        $head = (array)$head;
	        $nota = DB::connection('omuster')->table('TX_HDR_NOTA')->where('nota_req_no', $head[$config['head_no']])->first();
	        $rec_dr = DB::connection('omuster')->table('TM_REFF')->where([
	          'reff_tr_id' => 5,
	          'reff_id' => $head[$config['head_from']]
	        ])->first();
	        return $json_body = '{
	          "action" : "getDelivery",
	          "header": {
	            "REQ_NO": "'.$head[$config['head_no']].'",
	            "REQ_DELIVERY_DATE": "'.$head[$config['head_date']].'",
	            "NO_NOTA": "'.$nota->nota_no.'",
	            "TGL_NOTA": "'.$nota->nota_date.'",
	            "NM_CONSIGNEE": "'.$head[$config['head_cust_name']].'",
	            "ALAMAT": "'.$head[$config['head_cust_addr']].'",
	            "REQ_MARK": "",
	            "NPWP": '.$head[$config['head_cust_npwp']].'",
	            "DELIVERY_KE": "",
	            "TANGGAL_LUNAS": "'.$nota->nota_paid_date.'",
	            "PERP_DARI": "",
	            "PERP_KE": ""
	          },
	          "arrdetail": ['.$arrdetil.']
	        }';
		}

		public static function sendRequestToExtJsonMet($arr){
	        $client = new Client();
	        $options= array(
	          'auth' => [
	            $arr['user'],
	            $arr['pass']
	          ],
	          'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json'],
	          'body' => $arr['json'],
	          "debug" => false
	        );
			try {
	          $res = $client->post($arr['target'], $options);
	        } catch (ClientException $e) {
	          $error = $e->getRequest() . "\n";
	          if ($e->hasResponse()) {
	            $error .= $e->getResponse() . "\n";
	          }
	          return ["Success"=>false, "request" => $options, "response" => $error];
	        }
	        $res = json_decode($res->getBody()->getContents(), true);
	        return ["Success"=>true, "request" => $arr, "response" => $res];
		}

		public static function sendInvProforma($arr){
			return ['Success' => true, 'sendInvProforma' => 'by pass dulu']; // by pass dulu
			$branch = DB::connection('mdm')->table('TM_BRANCH')->where('branch_id',$arr['nota']['nota_branch_id'])->where('branch_code',$arr['nota']['nota_branch_code'])->get();
			if (count($branch) == 0) {
				return ['Success' =>false, 'response' => 'branch not found!'];
			}
			$branch = (array)$branch[0];
			$nota_date = $arr['nota']['nota_date'];
			$nota_date_noHour = date('Y-m-d', strtotime($arr['nota']['nota_date']));

			$lines = '';
			if ($arr['nota']['nota_group_id'] == 1) { // rec
				$getNotaDtl = DB::connection('omuster')->table('TX_DTL_NOTA')->where('nota_hdr_id',$arr['nota']['nota_id'])->get();
				foreach ($getNotaDtl as $list) {
					$lines .= '
					{
						"billerRequestId": "'.$arr['nota']['nota_req_no'].'",
						"trxNumber": "'.$arr['nota']['nota_no'].'",
						"lineId": null,
						"lineNumber": "'.$list->dtl_line.'",
						"description": "'.$list->dtl_service_type.'",
						"memoLineId": null,
						"glRevId": null,
						"lineContext": "",
						"taxFlag": "Y",
						"serviceType": "'.$list->dtl_line_desc.'",
						"eamCode": "",
						"locationTerminal": "",
						"amount": "'.$list->dtl_dpp.'",
						"taxAmount": "'.$list->dtl_ppn.'",
						"startDate": "'.$nota_date_noHour.'",
						"endDate": "'.$nota_date_noHour.'",
						"createdBy": "-1",
						"creationDate": "'.$nota_date_noHour.'",
						"lastUpdatedBy": "-1",
						"lastUpdatedDate": "'.$nota_date_noHour.'",
						"interfaceLineAttribute1": "",
						"interfaceLineAttribute2": "'.$list->dtl_service_type.'",
						"interfaceLineAttribute3": "",
						"interfaceLineAttribute4": "",
						"interfaceLineAttribute5": "",
						"interfaceLineAttribute6": "",
						"interfaceLineAttribute7": "",
						"interfaceLineAttribute8": "",
						"interfaceLineAttribute9": "",
						"interfaceLineAttribute10": "",
						"interfaceLineAttribute11": "",
						"interfaceLineAttribute12": "",
						"interfaceLineAttribute13": "'.$list->dtl_qty.'",
						"interfaceLineAttribute14": "'.$list->dtl_unit_name.'",
						"interfaceLineAttribute15": "",
						"lineDoc": ""
					},';
				}
			}
	        $lines = substr($lines, 0,-1);

			$json = '
			{
			    "arRequestDoc": {
			        "esbHeader": {
			        	"internalId": "",
			        	"externalId": "",
			        	"timestamp": "",
			        	"responseTimestamp": "",
			        	"responseCode": "",
			        	"responseMessage": ""
			        },
			        "esbBody": [
			            {
			                "header": {
			                	"billerRequestId":"'.$arr['nota']['nota_req_no'].'",
			                	"orgId":"'.$branch['branch_org_id'].'",
			                	"trxNumber":"'.$arr['nota']['nota_no'].'",
			                	"trxNumberOrig":"",
			                	"trxNumberPrev":"",
			                	"trxTaxNumber":"",
			                	"trxDate":"'.$nota_date.'",
			                	"trxClass":"INV",
			                	"trxTypeId":"-1",
			                	"paymentReferenceNumber":"",
			                	"referenceNumber":"",
			                	"currencyCode":"'.$arr['nota']['nota_currency_code'].'",
			                    "currencyType": "",
			                    "currencyRate": "0",
			                    "currencyDate": null,
			                    "amount": "'.$arr['nota']['nota_amount'].'",
			                    "customerNumber": "'.$arr['nota']['nota_cust_id'].'",
			                    "customerClass": "",
			                    "billToCustomerId": "-1",
			                    "billToSiteUseId": "-1",
			                    "termId": null,
			                    "status": "P",
			                    "headerContext": "'.$arr['nota']['nota_context'].'",
			                    "headerSubContext": "'.$arr['nota']['nota_sub_context'].'",
			                    "startDate": null,
			                    "endDate": null,
			                    "terminal": "-",
			                    "vesselName": "'.$arr['nota']['nota_vessel_name'].'",
			                    "branchCode": "'.$arr['nota']['nota_branch_code'].'",
			                    "errorMessage": "",
			                    "apiMessage": "",
			                    "createdBy": "-1",
			                    "creationDate": "'.$nota_date.'",
			                    "lastUpdatedBy": "-1",
			                    "lastUpdateDate": "'.$nota_date.'",
			                    "lastUpdateLogin": "-1",
			                    "customerTrxIdOut": null,
			                    "processFlag": "",
			                    "attribute1": "'.$arr['nota']['nota_sub_context'].'",
			                    "attribute2": "'.$arr['nota']['nota_cust_id'].'",
			                    "attribute3": "'.$arr['nota']['nota_cust_name'].'",
			                    "attribute4": "'.$arr['nota']['nota_cust_address'].'",
			                    "attribute5": "'.$arr['nota']['nota_cust_npwp'].'",
			                    "attribute6": "",
			                    "attribute7": "",
			                    "attribute8": "",
			                    "attribute9": "",
			                    "attribute10": "",
			                    "attribute11": "",
			                    "attribute12": "",
			                    "attribute13": "",
			                    "attribute14": "'.$arr['nota']['nota_no'].'",
			                    "attribute15": "",
			                    "interfaceHeaderAttribute1": "",
			                    "interfaceHeaderAttribute2": "'.$arr['nota']['nota_vessel_name'].'",
			                    "interfaceHeaderAttribute3": "",
			                    "interfaceHeaderAttribute4": "",
			                    "interfaceHeaderAttribute5": "",
			                    "interfaceHeaderAttribute6": "",
			                    "interfaceHeaderAttribute7": "",
			                    "interfaceHeaderAttribute8": "",
			                    "interfaceHeaderAttribute9": "",
			                    "interfaceHeaderAttribute10": "",
			                    "interfaceHeaderAttribute11": "",
			                    "interfaceHeaderAttribute12": "",
			                    "interfaceHeaderAttribute13": "",
			                    "interfaceHeaderAttribute14": "",
			                    "interfaceHeaderAttribute15": "",
			                    "customerAddress": "'.$arr['nota']['nota_cust_address'].'",
			                    "customerName": "'.$arr['nota']['nota_cust_name'].'",
			                    "sourceSystem": "NPKSBILLING",
			                    "arStatus": "N",
			                    "sourceInvoice": "'.$arr['nota']['nota_context'].'",
			                    "arMessage": "",
			                    "customerNPWP": "'.$arr['nota']['nota_cust_npwp'].'",
			                    "perKunjunganFrom": null,
			                    "perKunjunganTo": null,
			                    "jenisPerdagangan": "",
			                    "docNum": "",
			                    "statusLunas": "",
			                    "tglPelunasan": "'.$nota_date_noHour.'",
			                    "amountTerbilang": "",
			                    "ppnDipungutSendiri": "'.$arr['nota']['nota_ppn'].'",
			                    "ppnDipungutPemungut": "",
			                    "ppnTidakDipungut": "",
			                    "ppnDibebaskan": "",
			                    "uangJaminan": "",
			                    "piutang": "'.$arr['nota']['nota_amount'].'",
			                    "sourceInvoiceType": "'.$arr['nota']['nota_context'].'",
			                    "branchAccount": "'.$arr['nota']['nota_branch_account'].'",
			                    "statusCetak": "",
			                    "statusKirimEmail": "",
			                    "amountDasarPenghasilan": "'.$arr['nota']['nota_dpp'].'",
			                    "amountMaterai": null,
			                    "ppn10Persen": "'.$arr['nota']['nota_ppn'].'",
			                    "statusKoreksi": "",
			                    "tanggalKoreksi": null,
			                    "keteranganKoreksi": ""
			                },
			                "lines": ['.$lines.']
			            }
			        ],
			        "esbSecurity": {
			            "orgId": "'.$branch['branch_org_id'].'",
			            "batchSourceId": "",
			            "lastUpdateLogin": "",
			            "userId": "",
			            "respId": "",
			            "ledgerId": "",
			            "respApplId": "",
			            "batchSourceName": ""
			        }
			    }
			}';
			// return json_decode($json, true);
			$json = json_encode(json_decode($json, true));
			$res = static::sendRequestToExtJsonMet([
	        	"user" => config('endpoint.esbPutInvoice.user'),
	        	"pass" => config('endpoint.esbPutInvoice.pass'), 
	        	"target" => config('endpoint.esbPutInvoice.target'), 
	        	"json" => $json
	        ]);
	        $hsl = true;
	        if ($res['response']['arResponseDoc']['esbBody'][0]['errorCode'] == 'F') {
	        	$hsl = false;
	        }
			return ['Success' => $hsl, 'sendInvProforma' => $res];
		}

		public static function sendInvPay($arr){
			// di by passs dulu
			return ['Success' => true, 'response' => 'by passs'];
			// di by passs dulu
			$branch = DB::connection('mdm')->table('TM_BRANCH')->where('branch_id',$arr['nota']['nota_branch_id'])->where('branch_code',$arr['nota']['nota_branch_code'])->get();
			if (count($branch) == 0) {
				return ['Success' =>false, 'response' => 'branch not found!'];
			}
			$branch = (array)$branch[0];
			$json = '
			{
				"arRequestDoc": {
					"esbHeader": {
						"internalId": "",
						"externalId": "",
						"timestamp": "",
						"responseTimestamp": "",
						"responseCode": "",
						"responseMessage": ""
					},
					"esbBody": [
						{
							"header": {
								"orgId": "'.$branch['branch_org_id'].'",
								"receiptNumber": "'.$arr['nota']['nota_no'].'",
								"receiptMethod": "BANK",
								"receiptAccount": "Mandiri IDR 120.00.4107201.3",
								"bankId": "105009",
								"customerNumber": "12777901",
								"receiptDate": "2019-11-28 20:15:08",
								"currencyCode": "IDR",
								"status": "P",
								"amount": "20000",
								"processFlag": "",
								"errorMessage": "",
								"apiMessage": "",
								"attributeCategory": "UPER",
								"referenceNum": "",
								"receiptType": "",
								"receiptSubType": "",
								"createdBy": "-1",
								"creationDate": "2019-11-28",
								"terminal": "",
								"attribute1": "",
								"attribute2": "",
								"attribute3": "",
								"attribute4": "",
								"attribute5": "",
								"attribute6": "",
								"attribute7": "",
								"attribute8": "",
								"attribute9": "",
								"attribute10": "",
								"attribute11": "",
								"attribute12": "",
								"attribute13": "",
								"attribute14": "BRG10",
								"attribute15": "",
								"statusReceipt": "N",
								"sourceInvoice": "BRG",
								"statusReceiptMsg": "",
								"invoiceNum": "",
								"amountOrig": null,
								"lastUpdateDate": "2019-11-28",
								"lastUpdateBy": "-1",
								"branchCode": "BTN",
								"branchAccount": "081",
								"sourceInvoiceType": "NPKBILLING",
								"remarkToBankId": "BANK_ACCOUNT_ID",
								"sourceSystem": "NPKBILLING",
								"comments": "Pembayaran uper",
								"cmsYn": "N",
								"tanggalTerima": null,
								"norekKoran": ""
							}
						}
					],
					"esbSecurity": {
						"orgId": "1822",
						"batchSourceId": "",
						"lastUpdateLogin": "",
						"userId": "",
						"respId": "",
						"ledgerId": "",
						"respApplId": "",
						"batchSourceName": ""
					}
				}
			}
			';

			$res = static::sendRequestToExtJsonMet([ // kirim putReceipt
				"user" => config('endpoint.esbPutReceipt.user'),
				"pass" => config('endpoint.esbPutReceipt.pass'), 
				"target" => config('endpoint.esbPutReceipt.target'), 
				"json" => $json
			]);
		}

		public static function getRealRecPLG($input){
			$find = DB::connection('omuster')->table('TX_HDR_REC')->where('REC_ID', $input['rec_id'])->first();
			$dtlLoop = DB::connection('omuster')->table('TX_DTL_REC')->where('REC_HDR_ID', $input['rec_id'])->where('REC_DTL_ISACTIVE','Y')->get();
			$dtl = '';
			$arrdtl = [];
			foreach ($dtlLoop as $list) {
				$dtl .= '
				{
					"NO_CONTAINER": "'.$list->rec_dtl_cont.'",
					"NO_REQUEST": "'.$find->rec_no.'",
					"BRANCH_ID": "'.$find->rec_branch_id.'"
				},';
				$arrdtlset = [
					"NO_CONTAINER" => $list->rec_dtl_cont,
					"NO_REQUEST" => $find->rec_no,
					"BRANCH_ID" => $find->rec_branch_id
				];
				$arrdtl[] = $arrdtlset;
			}
			$head = [
				"action" => "generateGetIn",
				"data" => $arrdtl
			];
	        $dtl = substr($dtl, 0,-1);
			$json = '
			{
				"action" : "generateGetIn",
				"data": ['.$dtl.']
			}';
			$arr = [
	        	"user" => config('endpoint.tosGetPLG.user'),
	        	"pass" => config('endpoint.tosGetPLG.pass'), 
	        	"target" => config('endpoint.tosGetPLG.target'), 
	        	"json" => '{ "request" : "'.base64_encode(json_encode(json_decode($json,true))).'"}'
	        ];
			$res = static::sendRequestToExtJsonMet($arr);
			$res = static::decodeResultAftrSendToTosNPKS($res);
			$his_cont = [];
			if ($res['response']['count'] > 0) {
				foreach ($res['response']['result'] as $listR) {
					$findGATI = [
						'GATEIN_CONT' => $listR['NO_CONTAINER'],
						'GATEIN_REQ_NO' => $listR['NO_REQUEST'],
						'GATEIN_BRANCH_ID' => $find->rec_branch_id,
						'GATEIN_BRANCH_CODE' => $find->rec_branch_code
					];
					$cek = DB::connection('omuster')->table('TX_GATEIN')->where($findGATI)->first();
					$datenow    = Carbon::now()->format('Y-m-d');
					$storeGATI = [
						"gatein_cont" => $listR['NO_CONTAINER'],
						"gatein_req_no" => $listR['NO_REQUEST'],
						"gatein_pol_no" => $listR['NOPOL'],
						"gatein_cont_status" => $listR['STATUS'],
						// "gatein_seal_no" => $listR[''],
						// "gatein_trucking" => $listR[''],
						// "gatein_yard" => $listR[''],
						// "gatein_mark" => $listR[''],
						"gatein_date" => date('Y-m-d', strtotime($listR['TGL_IN'])),
						"gatein_create_date" => \DB::raw("TO_DATE('".$datenow."', 'YYYY-MM-DD HH24:MI')"),
						"gatein_create_by" => $input['user']->user_id,
						"gatein_branch_id" => $find->rec_branch_id,
						"gatein_branch_code" => $find->rec_branch_code
					];
					if (empty($cek)) {
						DB::connection('omuster')->table('TX_GATEIN')->insert($storeGATI);
					}else{
						DB::connection('omuster')->table('TX_GATEIN')->where($findGATI)->update($storeGATI);
					}
					$findTsCont = [
						'cont_no' => $listR['NO_CONTAINER'],
						'branch_id' => $find->rec_branch_id,
						'branch_code' => $find->rec_branch_code
					];
					$cekTsCont = DB::connection('omuster')->table('TS_CONTAINER')->where($findTsCont)->orderBy('cont_counter', 'desc')->first();
					$cont_counter = $cekTsCont->cont_counter+1;
					$cekKegiatan = DB::connection('omuster')->table('TM_REFF')->where([
						"reff_tr_id" => 12,
						"reff_name" => 'GATE IN'
					])->first();
					$arrStoreTsContAndTxHisCont = [
						'cont_no' => $listR['NO_CONTAINER'],
						'branch_id' => $find->rec_branch_id,
						'branch_code' => $find->rec_branch_code,
						'cont_location' => 'GATI',
						'cont_size' => null,
						'cont_type' => null,
						'cont_counter' => $cont_counter,
						'no_request' => $listR['NO_REQUEST'],
						'kegiatan' => $cekKegiatan->reff_id,
						'id_user' => $input["user"]->user_id,
						'status_cont' => $listR['STATUS'],
						'vvd_id' => $find->rec_vvd_id
					];
					$his_cont[] = PlgRequestBooking::storeTsContAndTxHisCont($arrStoreTsContAndTxHisCont);
				}
				$Success = true;
				$msg = 'Success get realisasion';
			}else{
				$Success = false;
				$msg = 'realisasion not finish';
			}
			$res['his_cont'] = $his_cont;
			$dtl = DB::connection('omuster')->table('TX_DTL_REC')
				->leftJoin('TX_GATEIN', function($join) use ($find){
					$join->on('TX_GATEIN.gatein_cont', '=', 'TX_DTL_REC.rec_dtl_cont');
					$join->on('TX_GATEIN.gatein_req_no', '=', DB::raw("'".$find->rec_no."'"));
				})->where('REC_HDR_ID', $input['rec_id'])->where('REC_DTL_ISACTIVE','Y')->get();
	        return [
	        	'response' => $Success,
	        	'result' => $msg,
	        	'no_rec' =>$find->rec_no,
	        	'hdr' =>$find,
	        	'dtl' => $dtl,
	        	'getRealRecPLG' => $res
	        ];
		}
	// PLG
}