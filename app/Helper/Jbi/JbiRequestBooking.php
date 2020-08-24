<?php

namespace App\Helper\Jbi;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Helper\Jbi\JbiEInvo;
use App\Helper\Jbi\JbiFunctTOS;
use App\Helper\Jbi\JbiConnectedExternalApps;
use App\Helper\Jbi\JbiGenerateTariff;
use App\Helper\Jbi\JbiContHist;
use App\Helper\Jbi\SendTos;
use App\Helper\Jbi\FileUpload;

use App\Models\OmUster\TxHdrNota_ilcs;
use App\Models\OmUster\TxPayment_ilcs;

class JbiRequestBooking{
	// JBI
		private static function migrateNotaData($find, $config, $findCanc){
			if (in_array($config['kegiatan'], [8]) and $find[$config['head_status']] == 2) {
				return ['result' => null, "Success" => true];
			}
			$datenow = Carbon::now()->format('Y-m-d');
			$no_nota = '';
			if (empty($findCanc)) {
				$findReqNo = $find[$config['head_no']];
			}else{
				$findReqNo = $findCanc->cancelled_no;
			}
			$query = "SELECT * FROM V_PAY_SPLIT WHERE booking_number= '".$findReqNo."'";
			$tarifs = DB::connection('eng_ilcs')->select(DB::raw($query));
			if (count($tarifs) == 0) {
				return ['result' => "Fail, proforma and tariff not found!", "Success" => false];
			}
			foreach ($tarifs as $tarif) {
				$tarif = (array)$tarif;
				$cekOldNota = TxHdrNota_ilcs::where('nota_req_no', $findReqNo)->first();
				if (empty($cekOldNota)) {
					$headU = new TxHdrNota_ilcs;
				}else{
					$headU = TxHdrNota_ilcs::find($cekOldNota->nota_id);
				}
				$headU->app_id = $find['app_id'];
				$headU->nota_group_id = $tarif['nota_id'];
				$headU->nota_org_id = $tarif['branch_org_id'];
				$headU->nota_cust_id = $tarif['customer_id'];
				$headU->nota_cust_name = $tarif['alt_name'];
				$headU->nota_cust_npwp = $tarif['npwp'];
				$headU->nota_cust_address = $tarif['address'];
				$headU->nota_date = \DB::raw("TO_DATE('".$datenow."', 'YYYY-MM-DD HH24:MI')");
				$headU->nota_amount = $tarif['total'];
				$headU->nota_currency_code = $tarif['currency'];
				$headU->nota_status = 1;
				$headU->nota_context = $tarif['nota_context'];
				$headU->nota_sub_context = $tarif['nota_sub_context'];
				$headU->nota_service_code = $tarif['nota_service_code'];
				$headU->nota_branch_account = $tarif['branch_account'];
				$headU->nota_tax_code = $tarif['tax_code'];
				// $headU->nota_terminal = $find[$config['head_terminal_name']];
				$headU->nota_branch_id = $tarif['branch_id'];
				$headU->nota_branch_code = $tarif['branch_code'];
				$headU->nota_vessel_name = $find[$config['head_vessel_name']];
				// $headU->ukk = 'ukk';
				$headU->nota_trade_type = $tarif['trade_type'];
				$headU->nota_req_no = $tarif['booking_number'];
				// $headU->nota_real_no = '';
				$headU->nota_ppn = $tarif['ppn'];
				// $headN->nota_paid = $getH->; // pasti null
		        // $headN->nota_paid_date = $getH->; // pasti null
		        // $headN->rest_payment = $getH->; // pasti null
				$headU->nota_dpp = $tarif['dpp'];
				if ($config['head_pbm_id'] != null) {
					$headU->nota_pbm_id = $find[$config['head_pbm_id']];
				}
				if ($config['head_pbm_name'] != null) {
					$headU->nota_pbm_name = $find[$config['head_pbm_name']];
				}
				if ($config['head_shipping_agent_id'] != null) {
					$headU->nota_stackby_id = $find[$config['head_shipping_agent_id']];
				}
				if ($config['head_shipping_agent_name'] != null) {
					$headU->nota_stackby_name = $find[$config['head_shipping_agent_name']];
				}
				$headU->nota_req_date = $find[$config['head_date']];
				$headU->save();

				$headU = TxHdrNota_ilcs::find($headU->nota_id);
				if (empty($headU)) {
					return ['result' => 'fail, error went store to nota hdr!', "Success" => false];
				}
				$no_nota .= $headU->nota_no.', ';
				$queryAgain = "SELECT * FROM TX_TEMP_TARIFF_SPLIT WHERE TEMP_HDR_ID = '".$tarif['temp_hdr_id']."' AND CUSTOMER_ID = '".$tarif['customer_id']."'";
				$group_tariff = DB::connection('eng_ilcs')->select(DB::raw($queryAgain));

				$countLine = 0;
				DB::connection('omuster_ilcs')->table('TX_DTL_NOTA')->where('nota_hdr_id',$headU->nota_id)->delete();
				foreach ($group_tariff as $grpTrf) {
					$grpTrf = (array)$grpTrf;
					$tarifD = DB::connection('eng_ilcs')->table('TX_TEMP_TARIFF_DTL')->where('TEMP_HDR_ID',$tarif['temp_hdr_id'])->where('group_tariff_id',$grpTrf['group_tariff_id'])->get();

					foreach ($tarifD as $list) {
						$countLine++;
						$list = (array)$list;

						#jika req barang dan komponen tariff pass truck ambil qty_truck
						if($list['package_id'] == 5 && $list['group_tariff_id'] == 3){
							$list["qty"] = $list["qty_truck"];
						}

						$set_data = [
							"dtl_group_tariff_id" => $list["group_tariff_id"],
							"dtl_group_tariff_name" => $list["group_tariff_name"],
							"dtl_bl" => $list["no_bl"],
							"dtl_dpp" => $list["tariff_cal"],
							"dtl_commodity" => $list["commodity_name"],
							"dtl_equipment" => $list["equipment_name"],
							"dtl_masa_reff" => $list["stack_combine"],
									// "nota_dtl_id" => '',
							"nota_hdr_id" => $headU->nota_id,
							"dtl_line" => $countLine,
							"dtl_line_desc" => $list['memoline'],
									// "dtl_line_context" => , // perlu konfimasi
							"dtl_service_type" => $list['group_tariff_name'],
							"dtl_amount" => $list['total'],
							"dtl_ppn" => $list["ppn"],
							"dtl_masa" => $list["day_period"],
									// "dtl_masa1" => , // cooming soon
									// "dtl_masa12" => , // cooming soon
									// "dtl_masa2" => , // cooming soon
							"dtl_tariff" => $list["tariff"],
							"dtl_package" => $list["package_name"],
							"dtl_eq_qty" => $list["eq_qty"],
							"dtl_qty" => $list["qty"],
							"dtl_unit" => $list["unit_id"],
							"dtl_unit_qty" => $list["unit_qty"],
							"dtl_unit_name" => $list["unit_name"],
							"dtl_cont_size" => $list["cont_size"],
							"dtl_cont_type" => $list["cont_type"],
							"dtl_cont_status" => $list["cont_status"],
							"dtl_sub_tariff" => $list["sub_tariff"],
							"dtl_create_date" => \DB::raw("TO_DATE('".$datenow."', 'YYYY-MM-DD HH24:MI')")
						];
						DB::connection('omuster_ilcs')->table('TX_DTL_NOTA')->insert($set_data);
					}
				}
			}
			return ['result' => "Created Nota No : ".$no_nota, "Success" => true];
		}

		private static function canceledReqPrepare($input, $config, $up){
			$cnclHdr = DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_id',$input['id'])->first();
			if (empty($cnclHdr)) {
				return ['Success' => false, 'result_msg' => 'canceled request not found'];
			}
			$reqsHdr = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$cnclHdr->cancelled_req_no)->first();
			if (empty($reqsHdr)) {
				return ['Success' => false, 'result_msg' => 'canceled request not found'];
			}
			$reqsHdr = (array)$reqsHdr;
			if ($up == false) {
				return ['Success' => true, 'find' => $reqsHdr, 'canc' => (array)$cnclHdr];
			}
			$pluck = DB::connection('omuster_ilcs')->table('TX_DTL_CANCELLED')->where('cancl_hdr_id',$input['id'])->pluck('cancl_cont');
			if (empty($pluck) or empty($pluck[0])) {
				$pluck = DB::connection('omuster_ilcs')->table('TX_DTL_CANCELLED')->where('cancl_hdr_id',$input['id'])->pluck('cancl_si');
				if (empty($pluck) or empty($pluck[0])){
					return ['Success' => false, 'result_msg' => 'dtl canceled is null'];
				}
			}
			$cekStart = DB::connection('omuster_ilcs')->table($config['head_tab_detil'])
				->where($config['head_forigen'],$reqsHdr[$config['head_primery']])
				->whereIn($config['DTL_BL'],$pluck)
				->get();
			foreach ($cekStart as $cek) {
				$cek = (array)$cek;
				if ($cek[$config['DTL_FL_REAL']] != 1) {
					return [
						'Success' => false,
						'no_item' => $cek[$config['DTL_BL']],
						'result_msg' => 'Fail, '.$cek[$config['DTL_BL']].' telah masuk tahap realisasi'
					];
				}
			}
			$cnclDtl = DB::connection('omuster_ilcs')->table('TX_DTL_CANCELLED')->where('cancl_hdr_id',$cnclHdr->cancelled_id)->get();
			foreach ($cnclDtl as $list) {
				$noDtl = $list->cancl_cont.$list->cancl_si;
				$reqDtl = DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where([
					$config['head_forigen'] => $reqsHdr[$config['head_primery']],
					$config['DTL_BL'] => $noDtl
				])->first();
				if (empty($reqDtl)) {
					return [
						'Success' => false,
						'no_item' => $noDtl,
						'result_msg' => 'Fail, '.$noDtl.' tidak ditemukan'
					];
				}
				$reqDtl = (array)$reqDtl;
				if ($reqDtl[$config['DTL_FL_REAL']] != 1) {
					return [
						'Success' => false,
						'no_item' => $reqDtl[$config['DTL_BL']],
						'result_msg' => 'Fail, '.$reqDtl[$config['DTL_BL']].' sudah melakukan realisasi'
					];
				}
				if ($config['DTL_QTY'] == 1 or $config['kegiatan_batal'] == 21) {
					$reqDtlQty = 1;
				}else{
					$reqDtlQty = $reqDtl[$config['DTL_QTY']];
				}
				if ($list->cancl_qty > $reqDtlQty) {
					return [
						'Success' => false,
						'no_item' => $reqDtl[$config['DTL_BL']],
						'result_msg' => 'Fail, '.$reqDtl[$config['DTL_BL']].' qty yang dibatalkan melebihi data request'
					];
				}
			}
			static::canceledReqPrepareContainerOrBarang($config,$reqsHdr,$cnclHdr,$cnclDtl);
			return ['Success' => true, 'find' => $reqsHdr, 'canc' => (array)$cnclHdr];
		}

		private static function canceledReqPrepareContainerOrBarang($config,$reqsHdr,$cnclHdr,$cnclDtl){
			if (!empty($config['DTL_IS_ACTIVE'])) {
				$def = [
					$config['DTL_IS_ACTIVE'] => 'Y',
					$config['DTL_IS_CANCEL'] => 'N'
				];
			}else{
				$def = [
					$config['DTL_IS_CANCELLED'] => 'N',
					$config['DTL_QTY_CANC'] => 0
				];
			}
			DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where([
				$config['head_forigen'] => $reqsHdr[$config['head_primery']]
			])->update($def);
			foreach ($cnclDtl as $list) {
				$noDtl = $list->cancl_cont.$list->cancl_si;
				// Tambahan Untuuk cancel
				if (!empty($config['DTL_IS_CANCEL']) && !isset($config['DTL_IS_CANCELLED'])){
					$upd = [
						$config['DTL_IS_ACTIVE'] => 'N',
						$config['DTL_IS_CANCEL'] => 'Y'
					];
				}else{
					$upd = [
						$config['DTL_IS_CANCELLED'] => 'Y',
						$config['DTL_QTY_CANC'] => $list->cancl_qty
					];
				}
				DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where([
					$config['head_forigen'] => $reqsHdr[$config['head_primery']],
					$config['DTL_BL'] => $noDtl
				])->update($upd);
			}

			// Tambahan Change Header Flag
			$dtlIsActive = DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where([
				$config['head_forigen'] => $reqsHdr[$config['head_primery']],
				// $config['DTL_IS_ACTIVE'] => 'Y',
				$config['DTL_IS_CANCEL'] => 'N'
			])->get();

			if (count($dtlIsActive) == 0) {
				$updateHdrFlagCancel = DB::connection('omuster_ilcs')
																	->table($config['head_table'])
																	->where($config['head_primery'], $reqsHdr[$config['head_primery']])
																	->update([$config['head_status'] => 9]);
			}
		}

		private static function changeRecRemaningQty($input,$config,$find,$findCanc){
			if ($input['nota_id'] != 22) {
				return ['Success' => true, 'result' => null];
			}
			$cnf21 = DB::connection('mdm_ilcs')->table('TS_NOTA')->where('nota_id', 21)->first();
			$cnf21 = json_decode($cnf21->api_set, true);
			$cnf22 = $config;
			if (empty($findCanc)) {
				$loopDtlDel = DB::connection('omuster_ilcs')->table($cnf22['head_tab_detil'])->where($cnf22['head_forigen'],$find[$cnf22['head_primery']])->get();
				foreach ($loopDtlDel as $dtlDel) {
					$dtlDel = (array)$dtlDel;
					$getRecDtlSI = DB::connection('omuster_ilcs')->table($cnf21['head_tab_detil'])->where([
						$cnf21['DTL_BL'] => $dtlDel[$cnf22['DTL_BL']]
					])->first();
					if (empty($getRecDtlSI)) {
						return [
							'Success' => false,
							'no_item' => $dtlDel[$cnf22['DTL_BL']],
							'result_msg' => 'Fail, '.$dtlDel[$cnf22['DTL_BL']].' tidak ditemukan'
						];
					}

					$getRecDtlSI = (array)$getRecDtlSI;
					if ($getRecDtlSI['rec_cargo_remaining_qty'] < $dtlDel['del_cargo_dtl_qty']) {
						return [
							'Success' => false,
							'no_item' => $dtlDel[$cnf22['DTL_BL']],
							'result_msg' => 'Fail, '.$dtlDel[$cnf22['DTL_BL']].' qty melebihi yang telah direquest delivery'
						];
					}
				}

				foreach ($loopDtlDel as $dtlDel){
					$dtlDel = (array)$dtlDel;
					$getRecDtlSI = DB::connection('omuster_ilcs')->table($cnf21['head_tab_detil'])->where([
						$cnf21['DTL_BL'] => $dtlDel[$cnf22['DTL_BL']]
					])->first();
					$getRecDtlSI = (array)$getRecDtlSI;
					$up = $getRecDtlSI['rec_cargo_remaining_qty'] - $dtlDel['del_cargo_dtl_qty'];
					DB::connection('omuster_ilcs')->table($cnf21['head_tab_detil'])->where([
						$cnf21['DTL_BL'] => $dtlDel[$cnf22['DTL_BL']]
					])->update([
						'rec_cargo_remaining_qty' => $up
					]);

				}
				return ['Success' => true, 'result' => count($loopDtlDel)];
			}else{
				$loopDtlCanc = DB::connection('omuster_ilcs')->table('TX_DTL_CANCELLED')->where('cancl_hdr_id',$findCanc->cancelled_id)->get();
				foreach ($loopDtlCanc as $dtlCanc) {
					$getRecDtlSI = DB::connection('omuster_ilcs')->table($cnf21['head_tab_detil'])->where([
						$cnf21['DTL_BL'] => $dtlCanc->cancl_si
					])->first();
					$getRecDtlSI = (array)$getRecDtlSI;
					$up = $getRecDtlSI['rec_cargo_remaining_qty'] + $dtlCanc->cancl_qty;
					DB::connection('omuster_ilcs')->table($cnf21['head_tab_detil'])->where([
						$cnf21['DTL_BL'] => $dtlCanc->cancl_si
					])->update([
						'rec_cargo_remaining_qty' => $up
					]);
				}
				return ['Success' => true, 'result' => count($loopDtlCanc)];
			}
		}

	    public static function sendRequestJBI($input){
			$config = DB::connection('mdm_ilcs')->table('TS_NOTA')->where('nota_id', $input['nota_id'])->where('branch_id','10')->first();
			if (empty($config) or empty($config->api_set)) {
				return ['Success' => false, 'result_msg' => "Fail, nota not set!"];
			}
			if ($config->flag_status == 'N') {
				return ['Success' => false, 'result_msg' => "Fail, nota not active!"];
			}
			$config = json_decode($config->api_set, true);

			// request batal
			$canceledReqPrepare = null;
			if (!empty($input['canceled']) and $input['canceled'] == 'true') {
				$canceledReqPrepare = static::canceledReqPrepare($input, $config, false);
				if ($canceledReqPrepare['Success'] == false) {
					return $canceledReqPrepare;
				}
			}
			// request batal

			if (empty($canceledReqPrepare)) {
				$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->first();
			}else{
				$find = $canceledReqPrepare['find'];
			}
			if (empty($find)) {
				return ['Success' => false, 'result_msg' => "Fail, requst not found!"];
			}
			$find = (array)$find;
			if ($find[$config['head_status']] == 3 and empty($canceledReqPrepare)) {
				return ['Success' => false, 'result_msg' => "Fail, requst already send!"];
			}

			$his_cont = [];
			$tariffResp = JbiGenerateTariff::calculateTariffBuild($find, $input, $config, $canceledReqPrepare);
			if (empty($tariffResp['result_flag']) or $tariffResp['result_flag'] != 'S') {
				return $tariffResp;
			} else if ($tariffResp['result_flag'] == 'S' and empty($canceledReqPrepare)) {
				DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->update([
					$config['head_status'] => 2
				]);
				if (!empty($input['canceled']) and $input['canceled'] == 'true') {
					$confKgt = $config['kegiatan_batal'];
				}else{
					$confKgt = $config['kegiatan'];
				}
				if (is_array($confKgt)) {
					$confKgt = $confKgt[0];
				}
				if (!in_array($confKgt, [10,11])) {
					foreach ($tariffResp['detil_data'] as $list) {
						$list = (array)$list;
						$his_cont = JbiContHist::saveHisCont($find,$list,$config,$input,$confKgt);
					}
				}
			}
			$tariffResp['his_cont'] = $his_cont;
			if (!empty($canceledReqPrepare)) {
				DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_id',$input['id'])->update(['cancelled_status'=>2]);
			}
			return $tariffResp;
	    }

	    public static function viewTempTariffJBI($input){
	    	//print_r($input);die;
			$config = DB::connection('mdm_ilcs')
				->table('TS_NOTA')
				->where('nota_id', $input['nota_id'])
				->where('branch_id', $input['branch_id'])
				->where('branch_code', $input['branch_code'])
				->first();
			$config = json_decode($config->api_set, true);

			$findCanc = null;
	    	if (!empty($input['canceled']) and $input['canceled'] == 'true') {
				$findCanc = DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_id',$input['id'])->first();
				$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$findCanc->cancelled_req_no)->get();
			}else{
		    	$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->get();
			}
	    	$find = (array)$find[0];
	    	if (count($find) == 0) {
	    		return ['Success' => false, 'result' => 'fail, not found data!'];
	    	}
	    	if (empty($findCanc)) {
		    	$query = "SELECT * FROM V_PAY_SPLIT WHERE booking_number= '".$find[$config['head_no']]."'";
	    	}else{
	    		$query = "SELECT * FROM V_PAY_SPLIT WHERE booking_number= '".$findCanc->cancelled_no."'";
	    	}

	    	$result = JbiGenerateTariff::showTempTariff($query, $config, $find);

			return [ "Success" => true, "result" => $result];
		}

		private static function cekReqOrCanc($input,$config){
			$migrateTariff = true;
			$findCanc = null;
			if (!empty($input['canceled']) and $input['canceled'] == 'true') {
				$findCanc = DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_id',$input['id'])->first();
				$migrateTariff = false;
			}

			if (empty($findCanc)) {
				$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->get();
				if (empty($find)) {
					return ['result' => "Fail, requst not found!", "Success" => false];
				}
				$find = (array)$find[0];
				$retHeadNo = $find[$config['head_no']];
			}else{
				$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$findCanc->cancelled_req_no)->get();
				if (empty($find)) {
					return ['result' => "Fail, requst not found!", "Success" => false];
				}
				$find = (array)$find[0];
				$retHeadNo = $findCanc->cancelled_no;
			}
			$canceledReqPrepare = static::canceledReqPrepare($input, $config, true);
			return [
				"Success" => true,
				"migrateTariff" => $migrateTariff,
				'findCanc' => $findCanc,
				'find' => $find,
				'retHeadNo' => $retHeadNo
			];
		}

	    public static function approvalRequestJBI($input){
			$config = DB::connection('mdm_ilcs')->table('TS_NOTA')->where('nota_id', $input['nota_id'])->first();
			$config = json_decode($config->api_set, true);
			$cekReqOrCanc = static::cekReqOrCanc($input,$config);
			if ($cekReqOrCanc['Success'] == false) {
				return $cekReqOrCanc;
			}
			$findCanc = $cekReqOrCanc['findCanc'];
			$find = $cekReqOrCanc['find'];
			$retHeadNo = $cekReqOrCanc['retHeadNo'];
			$migrateTariff = $cekReqOrCanc['migrateTariff'];
			if (
				(empty($findCanc) and $find[$config['head_status']] == 3 and $input['approved'] == 'true') or
				(!empty($findCanc) and $findCanc->cancelled_status == 3 and $input['approved'] == 'true')
			) {
				return ['result' => "Fail, requst already approved!", 'no_req' => $retHeadNo, "Success" => false];
			}
			$nota = DB::connection('omuster_ilcs')->table('TX_HDR_NOTA')->where('nota_req_no',$retHeadNo)->whereNotIn('nota_status', [4])->get();
			if (count($nota) > 0) {
				return ['result' => "Fail, request already exist on proforma!", 'no_req' => $retHeadNo, "Success" => false];
			}

			if ($input['approved'] == 'false') {
				if (empty($findCanc)){
					 DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->update([
						$config['head_status'] => 4,
						$config['head_mark'] => $input['msg']
					]);
				}else{
					DB::connection('omuster_ilcs')->table('tx_hdr_cancelled')->where('cancelled_id',$input['id'])->update([
						'cancelled_status' => 4,
						'cancelled_mark' => $input['msg']
					]);
				}

				return ['result' => "Success, rejected requst", 'no_req' => $retHeadNo];
			}

			$changeRecRemaningQty = static::changeRecRemaningQty($input,$config,$find,$findCanc);
			if ($changeRecRemaningQty['Success'] == false) {
				return $changeRecRemaningQty;
			}

			if ($find[$config['head_paymethod']] == 2) {
				$migrateTariff = false;
			}
			$pesan = [];
			$pesan['result'] = null;
			if ($migrateTariff == true) {
				$pesan = static::migrateNotaData($find, $config, $findCanc);
				if ($pesan['Success'] == false) {
					return $pesan;
				}
			}

			$sendRequestBooking = null;
			if ($find[$config['head_paymethod']] == 2 or !empty($findCanc)) {
				if (!empty($findCanc)) {
					$id = $findCanc->cancelled_id;
		        	$table = 'TX_HDR_CANCELLED';
				}else{
					$id = $input['id'];
		        	$table = $config['head_table'];
				}
				$sendRequestBooking = JbiFunctTOS::sendRequestBookingJBI(['id' => $id, 'table' =>$table, 'config' => $config]);
				if (empty($sendRequestBooking['sendRequestBookingJBI'])) {
					return ['result' => "Fail, error went send request to TOS!", 'no_req' => $retHeadNo, "Success" => false];
				}
			}

			if (empty($findCanc)){
				DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->update([
					$config['head_status'] => 3,
					$config['head_mark'] => $input['msg']
				]);
			}else{
				DB::connection('omuster_ilcs')->table('tx_hdr_cancelled')->where('cancelled_id',$input['id'])->update([
					'cancelled_status' => 9,
					'cancelled_mark' => $input['msg']
				]);
			}

			return [
				'result' => "Success, approved request! ".$pesan['result'],
				"note" => $pesan['result'],
				'no_req' => $retHeadNo,
				'sendRequestBooking' => $sendRequestBooking,
				'changeRecRemaningQty' => $changeRecRemaningQty
			];
		} // end approvalRequestJbi

	    public static function confirmRealisasion($input){
			$config = DB::connection('mdm_ilcs')
				->table('TS_NOTA')
				->where('nota_id', $input['nota_id'])
				->where('branch_id', $input['branch_id'])
				->where('branch_code', $input['branch_code'])
				->first();
			$config = json_decode($config->api_set, true);
			$find = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->first();
			$find = (array)$find;
			if ($find[$config['head_status']] == 5) {
				return [
					'Success' => false,
					'result' => "Fail, realisasion is confirmed!",
					'no_req' => $find[$config['head_no']]
				];
			}

			if ($input['nota_id'] != 20 /*brg rec*/ or $input['nota_id'] != 21 /*brg del*/) { // tdk samsa deng_ilcsan req brg
				$notIN = [$config['DTL_FL_REAL_F'][count($config['DTL_FL_REAL_F'])-1]];
				$dtl = DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where($config['head_forigen'], $input['id'])->where($config['DTL_IS_CANCEL'],'N')->whereNotIn($config['DTL_FL_REAL'], $notIN)->get();
				if (count($dtl) > 0) {
					return [
						'Success' => false,
						'result' => "Fail, realisasion is not finish!",
						'no_req' => $find[$config['head_no']]
					];
				}
			}

			$pesan = [];
			$pesan['result'] = null;
			$tariffResp['result_flag'] = null;
			// disable piutang?
			// if ($find[$config['head_paymethod']] == 2) {
			// 	// calculate tariff
			// 		$tariffResp = JbiGenerateTariff::calculateTariffBuild($find, $input, $config, null);
			// 		if ($tariffResp['result_flag'] != 'S') {
			// 			return $tariffResp;
			// 		}
			// 	// calculate tariff
			// 	// migrate nota
			// 		$pesan = static::migrateNotaData($find, $config, null);
			// 		if ($pesan['Success'] == false) {
			// 			return $pesan;
			// 		}
			// 	// migrate nota
			// }
			DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_primery'],$input['id'])->update([
				$config['head_status'] => 5
			]);
			if (!empty($config['DTL_IS_ACTIVE'])) {
				DB::connection('omuster_ilcs')->table($config['head_tab_detil'])->where($config['head_forigen'],$input['id'])->update([
					$config['DTL_IS_ACTIVE'] => 'N'
				]);
			}
			// update utk gabungan
				if (in_array($input['nota_id'], [7,8,9,10])) {
					DB::connection('omuster_ilcs')->table('TX_HDR_REC')->where('rec_no',$find[$config['head_no']])->update([
						'rec_status' => 11
					]);
					DB::connection('omuster_ilcs')->table('TX_HDR_DEL')->where('del_no',$find[$config['head_no']])->update([
						'del_status' => 11
					]);
				}
			// update utk gabungan
			return [
				'result' => "Success, confirm realisasion! ".$pesan['result'],
				'note' => $pesan['result'],
				'no_req' => $find[$config['head_no']],
				'tariffResp' => $tariffResp
			];
	    }

	    public static function approvalProformaJBI($input){
	    	$getNota = TxHdrNota_ilcs::find($input['nota_id']);
            if (empty($getNota)) {
            	return ['result' => "Fail, proforma not found!", "Success" => false];
            }
	    	$cekNota = TxHdrNota_ilcs::where([
            	'nota_id'=>$input['nota_id'],
            	'nota_status'=>'1'
            ])->count();
            if ($cekNota = 0) {
            	return ['result' => "Fail, proforma not waiting approval!", 'nota_no' => $getNota->nota_no, "Success" => false];
            }
            $config = DB::connection('mdm_ilcs')->table('TS_NOTA')->where('nota_id', $getNota->nota_group_id)->first();
            $config = json_decode($config->api_set, true);
            $cekIsCanc = DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_no', $getNota->nota_req_no)->first();
            if ($input['approved'] == 'true') {
            	$getNota->nota_status = 2;
            	$getNota->save();
            	$msg='Success, approved!';
            }else if ($input['approved'] == 'false') {
            	$getNota->nota_status = 4;
            	$getNota->save();
            	if (empty($cekIsCanc)) {
					$getReq = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$getNota->nota_req_no)->first();
					$getReq = (array)$getReq;
					if ($getReq[$config['head_paymethod']] == 1) {
						DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$getNota->nota_req_no)->update([$config['head_status'] => 4 ]);
					}else if($getReq[$config['head_paymethod']] == 2) {
						DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$getNota->nota_req_no)->update([$config['head_status'] => 3 ]);
					}
            	}else{
            		DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_no', $getNota->nota_req_no)->update(['cancelled_status'=>4]);
            	}

            	$msg='Success, rejected!';
            }
            return ['result' => $msg, 'nota_no' => $getNota->nota_no];
	    }

	    public static function storePaymentJBI($input){
	    	$getNota = TxHdrNota_ilcs::where([ 'nota_no'=>$input['pay_nota_no'] ])->first();
	    	$config = DB::connection('mdm_ilcs')->table('TS_NOTA')->where('nota_id', $getNota->nota_group_id)->first();
        	$config = json_decode($config->api_set, true);
            $cekNota = TxHdrNota_ilcs::where([
            	'nota_no'=>$input['pay_nota_no'],
            	'nota_paid'=>'Y'
            ])->count();
            if ($cekNota > 0) {
            	return ['result' => "Fail, invoice already paid!", "Success" => false, 'nota_no'=>$input['pay_nota_no']];
            }
            $cekNota = TxHdrNota_ilcs::where([
            	'nota_no'=>$input['pay_nota_no'],
            	'nota_status'=>2
            ])->count();
            if ($cekNota == 0) {
            	return ['result' => "Fail, proforma not approved!", "Success" => false, 'nota_no'=>$input['pay_nota_no']];
            }
			if (empty($input['pay_id'])) {
		    	$store = new TxPayment_ilcs;
		    	if (empty($input['pay_file']['PATH']) or empty($input['pay_file']['BASE64']) or empty($input['pay_file'])) {
	              return ["Success"=>false, "result" => "Fail, file is required"];
	            }
			}else{
				$store = TxPayment_ilcs::find($input['pay_id']);
				if (!empty($input['pay_file']['PATH']) and !empty($input['pay_file']['BASE64']) and !empty($input['pay_file'])) {
					if (file_exists($store->pay_file)){
						unlink($store->pay_file);
					}
	      }
			}

	    	// pay_id            number,
	    	// pay_no            varchar2(20 byte),
	    	$store->pay_nota_no = $input['pay_nota_no'];
	    	$store->pay_req_no = $input['pay_req_no'];
	    	$store->pay_method = $input['pay_method'];
	    	$store->pay_cust_id = $input['pay_cust_id'];
	    	$store->pay_cust_name = $input['pay_cust_name'];
	    	$store->pay_bank_code = $input['pay_bank_code'];
	    	$store->pay_bank_name = $input['pay_bank_name'];
	    	$store->pay_branch_id = $input['pay_branch_id'];
	    	$store->pay_branch_code = $getNota->nota_branch_code;
	    	$store->pay_account_no = $input['pay_account_no'];
	    	$store->pay_account_name = $input['pay_account_name'];
	    	$store->pay_amount = $getNota->nota_amount;
	    	$store->pay_date = \DB::raw("TO_DATE('".$input['pay_date']."', 'YYYY-MM-DD HH24:MI')");
	    	$store->pay_note = $input['pay_note'];
	    	$store->pay_create_by = $input['pay_create_by'];
	    	$store->pay_create_date = \DB::raw("TO_DATE('".$input['pay_date']."', 'YYYY-MM-DD HH24:MI')");
	    	$store->pay_type = $input['pay_type'];
	    	$store->save();

	    	$pay = TxPayment_ilcs::find($store->pay_id);
	    	if (!empty($input['pay_file']['PATH']) and !empty($input['pay_file']['BASE64']) and !empty($input['pay_file'])) {
	    		$directory  = 'omuster/TX_PAYMENT/'.date('d-m-Y').'/';
	    		$response   = FileUpload::upload_file($input['pay_file'], $directory, "TX_PAYMENT", $store->pay_id);
	    		if ($response['response'] == true) {
	    			TxPayment_ilcs::where('pay_id',$store->pay_id)->update([
	    				'pay_file' => $response['link']
	    			]);
	    		}
	    	}
            $cekIsCanc = DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_no', $getNota->nota_req_no)->first();
            $cekIsCanc = (array)$cekIsCanc;
	    	$arr = [
	    		'config' => $config,
	    		"nota" => (array)$getNota['attributes'],
	    		"payment" => (array)$pay['attributes'],
	    		'reqCanc' => $cekIsCanc
	    	];
        	$sendInvPay = JbiEInvo::sendInvPay($arr);
        	if (empty($sendInvPay['Success']) or $sendInvPay['Success'] == false) {
        		return [
        			'Success' => false,
        			'result' => 'Fail, cant send payment invoice',
        			'no_pay' => $pay->pay_no,
        			'nota_no' => $getNota->nota_no,
        			'no_req' => $pay->pay_req_no,
        			'sendInvPay' => $sendInvPay
        		];
        	}
        	$getNota->nota_status = 3;
        	$getNota->nota_paid_date = \DB::raw("TO_DATE('".$input['pay_date']."', 'YYYY-MM-DD HH24:MI')");
        	$getNota->nota_paid = 'Y';
        	$getNota->save();
        	if (empty($cekIsCanc)) {
	        	$getReq = DB::connection('omuster_ilcs')->table($config['head_table'])->where($config['head_no'],$getNota->nota_req_no)->first();
	        	$getReq = (array)$getReq;
	        	$id = $getReq[$config['head_primery']];
	        	$table = $config['head_table'];
        	}else{
        		$id = $cekIsCanc['cancelled_id'];
	        	$table = 'TX_HDR_CANCELLED';
        	}
        	$sendRequestBooking = null;
        	if (
        		(!empty($getReq) and $getReq[$config['head_paymethod']] == 1) or
        		!empty($cekIsCanc)
        	) {
        		$sendRequestBooking = JbiFunctTOS::sendRequestBookingJBI(['id' => $id, 'table' => $table, 'config' => $config]);
        		if (!empty($cekIsCanc)) {
        			DB::connection('omuster_ilcs')->table('TX_HDR_CANCELLED')->where('cancelled_id', $cekIsCanc['cancelled_id'])->update([
        				'cancelled_status' => 9
        			]);
        		}
        	}
					if (($config['head_table'] == 'TX_HDR_RELOKASI')or($config['head_table'] == 'TX_HDR_REC_CARGO')) {
						// only for relokasi jambi
						$send_data = SendTos::send_data($input['pay_nota_no']);
					}

					return [
						'result' => "Success, pay proforma!",
						'no_pay' => $pay->pay_no,
						'no_nota' => $input['pay_nota_no'],
						'no_req' => $pay->pay_req_no,
						'sendInvPay' => $sendInvPay,
						'sendRequestBooking' => $sendRequestBooking
					];

        	// if($send_data != 'success'){
        	// 	return [
        	// 		'Success' => false,
        	// 		'result' => 'Fail, cant send data',
        	// 		'no_pay' => $pay->pay_no,
        	// 		'nota_no' => $getNota->nota_no,
        	// 		'no_req' => $pay->pay_req_no,
        	// 		'sendInvPay' => $sendInvPay
        	// 	];
        	// }else{
        	// 	return [
	        // 		'result' => "Success, pay proforma!",
	        // 		'no_pay' => $pay->pay_no,
	        // 		'no_nota' => $input['pay_nota_no'],
	        // 		'no_req' => $pay->pay_req_no,
	        // 		'sendInvPay' => $sendInvPay,
	        // 		'sendRequestBooking' => $sendRequestBooking
        	// 	];
        	// }
	    }
	// JBI
}
