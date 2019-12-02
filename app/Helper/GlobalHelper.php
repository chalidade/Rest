<?php

namespace App\Helper;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GlobalHelper {

  public static function viewHeaderDetail($input) {
      $data    = $input["data"];
      $count   = count($input["data"]);
      $pk      = $input["HEADER"]["PK"][0];
      $pkVal   = $input["HEADER"]["PK"][1];
      foreach ($data as $data) {
        $val     = $input[$data];
        $connect  = DB::connection($val["DB"])->table($val["TABLE"]);
          if ($data == "HEADER") {
             $header   = $connect->where(strtoupper($pk), "like", strtoupper($pkVal))->get();
             $header   = json_decode(json_encode($header), TRUE);
             $vwdata = ["HEADER" => $header];
          }

          else if($data == "FILE") {
            $fil     = [];
            $fk      = $val["FK"][0];
            $fkhdr   = $header[0][$val["FK"][1]];
            $detail  = json_decode(json_encode($connect->where(strtoupper($fk), "like", strtoupper($fkhdr))->get()), TRUE);
            foreach ($detail as $list) {
              $newDt = [];
              foreach ($list as $key => $value) {
                $newDt[$key] = $value;
              }
              $dataUrl = "http://10.88.48.33/api/public/".$detail[0]["doc_path"];
              $url     = str_replace(" ", "%20", $dataUrl);
              $file = file_get_contents($url);
              $newDt["base64"]  =  base64_encode($file);
              $fil[] = $newDt;
              $vwdata[$data] = $fil;
              }
              if (empty($detail)) {
                $vwdata[$data] = [];
              }
            }

          else {
            $fk      = $val["FK"][0];
            $fkhdr   = $header[0][$val["FK"][1]];
            $detail  = $connect->where(strtoupper($fk), "like", strtoupper($fkhdr))->get();
            $vwdata[$data] = $detail;
          }
      }

      if (!empty($input["changeKey"])) {
        $result  = $vwdata;
        $data    = json_encode($result);
        $change  = str_replace($input["changeKey"][0], $input["changeKey"][1], $data);
        $vwdata  = json_decode($change);
      }

      if (isset($input["spesial"])) {
        if ($input["spesial"] == "TM_LUMPSUM") {
          $id       = $input["HEADER"]["PK"][1];
          $detail   = [];
          $cust     = [];
          $fil      = [];
          $data_a   = DB::connection("omcargo")->table('TS_LUMPSUM_AREA')->where("LUMPSUM_ID", "=", $id)->get();
          foreach ($data_a as $list) {
            $newDt = [];
            foreach ($list as $key => $value) {
              $newDt[$key] = $value;
            }

            $data_c = DB::connection("omcargo")->table('TM_REFF')->where([["REFF_TR_ID", "=", "11"],["REFF_ID", "=", $list->lumpsum_stacking_type]])->get();
            foreach ($data_c as $listc) {
              $newDt = [];
              foreach ($listc as $key => $value) {
                $newDt[$key] = $value;
              }
            }

          if ($list->lumpsum_stacking_type == "2") {
            $data_b = DB::connection("mdm")->table('TM_STORAGE')->where("storage_code", $list->lumpsum_area_code)->get();
          } else {
            $data_b = DB::connection("mdm")->table('TM_YARD')->where("yard_code", $list->lumpsum_area_code)->get();
          }
          foreach ($data_b as $listS) {
            foreach ($listS as $key => $value) {
              $newDt[$key] = $value;
            }
          }
          $detail[] = $newDt;
         }

         $data_d   = DB::connection("omcargo")->table('TS_LUMPSUM_CUST')->where("LUMPSUM_ID", "=", $id)->get();
         foreach ($data_d as $listD) {
           $custo = [];
           foreach ($listD as $key => $value) {
             $custo[$key] = $value;
           }
             $cust[] = $custo;
         }

         $vwdata["DETAIL"] = $detail;
         $vwdata["CUSTOMER"] = $cust;
         $no       = $vwdata["HEADER"][0]["lumpsum_no"];
         $data_e   = DB::connection("omcargo")->table('TX_DOCUMENT')->where("REQ_NO", "=", $id)->get();
         foreach ($data_e as $list) {
           $newDt = [];
           foreach ($list as $key => $value) {
             $newDt[$key] = $value;
           }
           $dataUrl = "http://10.88.48.33/api/public/".$list->doc_path;
           $url     = str_replace(" ", "%20", $dataUrl);
           $file = file_get_contents($url);
           $newDt["base64"]  =  base64_encode($file);
           $fil[] = $newDt;
           $vwdata["FILE"] = $fil;
           }
           if (empty($data_e)) {
             $vwdata["FILE"] = [];
           }
        }
      }
      return $vwdata;
    }

  public static function join_filter($action) {
      $result    = [];
      $table     = $action["table"];
      $schema    = $action["schema"];
      $head      = DB::connection($schema[0])->table($table[0]);

      for ($i=0; $i < count($action['filter_1']["data"]); $i++) {
      if ($action['filter_1']["type"] == "or") {
        if ($action['filter_1']["operator"][$i] != "like") $head->orWhere($action['filter_1']["data"][$i],$action['filter_1']["operator"][$i],$action['filter_1']["value"][$i]);
        else $head->orwhere($action['filter_1']["data"][$i], 'like', '%'.$action['filter_1']["value"][$i].'%');
      }

      else if($action['filter_1']["type"] == "and") $head->Where($action['filter_1']["data"][$i],$action['filter_1']["operator"][$i],$action['filter_1']["value"][$i]);
      else $head->orwhere($action['filter_1']["data"][$i], 'like', '%'.$action['filter_1']["value"][$i].'%');
      }

      $head = $head->get();
      foreach ($head as $list) {
        $newDt = [];
        foreach ($list as $key => $value) {
          $newDt[$key] = $value;
        }
        $relasia = $action['relation'][0];
        if (count($action["relation"]) > 1) {
          $relasib = $action['relation'][1];
          $detil = DB::connection($schema[1])->table($table[1])->where($action['relation'][1],  $list->$relasia);
        } else {
          $detil = DB::connection($schema[1])->table($table[1])->where($action['relation'][0],  $list->$relasia);
        }

        for ($i=0; $i < count($action['filter_2']["data"]); $i++) {
        if ($action['filter_2']["type"] == "or") {
          if ($action['filter_2']["operator"][$i] != "like") $detil->orWhere($action['filter_2']["data"][$i],$action['filter_2']["operator"][$i],$action['filter_2']["value"][$i]);
          else $detil->orwhere($action['filter_2']["data"][$i], 'like', '%'.$action['filter_2']["value"][$i].'%');
        }

        else if($action['filter_2']["type"] == "and") $detil->Where($action['filter_2']["data"][$i],$action['filter_2']["operator"][$i],$action['filter_2']["value"][$i]);
        else $detil->orwhere($action['filter_2']["data"][$i], 'like', '%'.$action['filter_2']["value"][$i].'%');
        }

        $detil = $detil->get();
        foreach ($detil as $listS) {
          foreach ($listS as $key => $value) {
            $newDt[$key] = $value;
          }
        }
        $result[] = $newDt;
      }
      return $result;
    }

  public static function index($input) {
      $connect  = \DB::connection($input["db"])->table($input["table"]);

      if (!empty($input["range"])) {
        $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
      }

      if (!empty($input["filter"])) {
      $search   = $input["filter"];
      foreach ($search as $value) {
        if ($value["operator"] == "like")
          $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
        else if($value["operator"] == "eq")
          $connect->whereDate($value["property"],'=',$value["value"]);
        else if($value["operator"] == "gt")
          $connect->whereDate($value["property"],'>=',$value["value"]);
        else if($value["operator"] == "lt")
          $connect->whereDate($value["property"],'<=',$value["value"]);
        }
      }

      if (!empty($input["selected"])) {
        $result  = $connect->select($input["selected"]);
      }

      if(!empty($input["orderby"][0])) {
      $in        = $input["orderby"];
      $connect->orderby($in[0], $in[1]);
      }

      if(!empty($input["where"][0])) {
        $connect->where($input["where"]);
      }

      if(!empty($input["whereIn"][0])) {
      $in        = $input["whereIn"];
      $connect->whereIn(strtoupper($in[0]), $in[1]);
      }

      $count    = $connect->count();
      if (!empty($input['start']) || $input["start"] == '0') {
        if (!empty($input['limit'])) {
          $connect->skip($input['start'])->take($input['limit']);
        }
      }
      $result   = $connect->get();

      return ["result"=>$result, "count"=>$count];
    }

  public static function filter($input) {
      $data      = $input['parameter']["data"];
      $value     = $input['parameter']["value"];
      $operator  = $input['parameter']["operator"];
      $type      = $input['parameter']["type"];
      $connect   = \DB::connection($input["db"])->table($input["table"]);

      if (!empty($input["filter"])) {
      $search   = $input["filter"];
      foreach ($search as $value) {
        if ($value["operator"] == "like")
          $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
        else if($value["operator"] == "eq")
          $connect->whereDate($value["property"],'=',$value["value"]);
        else if($value["operator"] == "gt")
          $connect->whereDate($value["property"],'>=',$value["value"]);
        else if($value["operator"] == "lt")
          $connect->whereDate($value["property"],'<=',$value["value"]);
        }
      }

      for ($i=0; $i < count($data); $i++) {
      $result[] = array(strtoupper($data[$i]),$operator[$i],strtoupper($value[$i]));

      if ($type == "or") {
        if ($operator[$i] != "like") $connect->orWhere($result);
        else $connect->orwhere(strtoupper($data[$i]), 'like', '%'.strtoupper($value[$i]).'%');
      }

      else if($type == "and") $connect->Where($result);
      else $connect->orwhere(strtoupper($data[$i]), 'like', '%'.strtoupper($value[$i]).'%');
      }

      if(!empty($input["orderby"][0])) {
      $in        = $input["orderby"];
      $connect->orderby($in[0], $in[1]);
      }

      if (!empty($input["range"])) {
        $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
      }

      if (!empty($input["selected"])) {
        $result  = $connect->select($input["selected"]);
      }

      $count   = $connect->count();
      if (!empty($input['start']) || $input["start"] == '0') {
        if (!empty($input['limit'])) {
          $connect->skip($input['start'])->take($input['limit']);
        }
      }

      if (!empty($input["changeKey"])) {
        $result  = $connect->get();
        $data    = json_encode($result);
        $change  = str_replace($input["changeKey"][0], $input["changeKey"][1], $data);
        $result  = json_decode($change);
      } else {
        $result  = $connect->get();
      }
      return ["result"=>$result, "count"=>$count];
    }

  public static function filterByGrid($input) {
    $connect  = \DB::connection($input["db"])->table($input["table"]);
    if (!empty($input["filter"])) {
    $search   = $input["filter"];
    foreach ($search as $value) {
      if ($value["operator"] == "like")
        $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
      else if($value["operator"] == "eq")
        $connect->whereDate($value["property"],'=',$value["value"]);
      else if($value["operator"] == "gt")
        $connect->whereDate($value["property"],'>=',$value["value"]);
      else if($value["operator"] == "lt")
        $connect->whereDate($value["property"],'<=',$value["value"]);
      }
    }

    if (!empty($input["range"])) {
      $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
    }


    if (!empty($input["selected"])) {
      $result  = $connect->select($input["selected"]);
    }

    if(!empty($input["orderby"][0])) {
    $in        = $input["orderby"];
    $connect->orderby(strtoupper($in[0]), $in[1]);
    }

    $count    = $connect->count();
    if (!empty($input['start']) || $input["start"] == '0') {
      if (!empty($input['limit'])) {
        $connect->skip($input['start'])->take($input['limit']);
      }
    }
    $result   = $connect->get();

    return ["result"=>$result, "count"=>$count];
  }

  public static function autoComplete($input) {
    $connect  = DB::connection($input["db"])->table($input["table"]);

    if(!empty($input["groupby"])) {
      $connect->groupBy(strtoupper($input["groupby"]));
    }

    if (!empty($input["range"])) {
      $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
    }

    if (!empty($input["selected"])) {
      $result  = $connect->select($input["selected"]);
    }

    if(!empty($input["orderby"][0])) {
    $in        = $input["orderby"];
    $connect->orderby(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["where"][0])) {
      $connect->where($input["where"]);
    }

    if(!empty($input["whereIn"][0])) {
    $in        = $input["whereIn"];
    $connect->whereIn(strtoupper($in[0]), $in[1]);
    }

    if (!empty($input["query"]) && !empty($input["field"])) {
      if (is_array($input["field"])) {
        foreach ($input["field"] as $field) {
          $upper = DB::connection($input["db"])->table($input["table"])->where(strtoupper($field),"like", "%".strtoupper($input["query"])."%")->get();
          $capit = DB::connection($input["db"])->table($input["table"])->where(strtoupper($field),"like", "%".ucwords(strtolower($input["query"]))."%")->get();
          $lower = DB::connection($input["db"])->table($input["table"])->where(strtoupper($field),"like", "%".strtolower($input["query"])."%")->get();

          if (!empty($upper)) {
            $connect->where(strtoupper($field),"like", "%".strtoupper($input["query"])."%");
          } else if(!empty($capit)) {
            $connect->where(strtoupper($field),"like", "%".ucwords(strtolower($input["query"]))."%");
          } else if(!empty($lower)) {
            $connect->where(strtoupper($field),"like", "%".strtolower($input["query"])."%");
          }
        }
      } else {
        $upper = DB::connection($input["db"])->table($input["table"])->where(strtoupper($input["field"]),"like", "%".strtoupper($input["query"])."%")->get();
        $capit = DB::connection($input["db"])->table($input["table"])->where(strtoupper($input["field"]),"like", "%".ucwords(strtolower($input["query"]))."%")->get();
        $lower = DB::connection($input["db"])->table($input["table"])->where(strtoupper($input["field"]),"like", "%".strtolower($input["query"])."%")->get();

        if (!empty($upper)) {
          $connect->where(strtoupper($input["field"]),"like", "%".strtoupper($input["query"])."%");
        } else if(!empty($capit)) {
          $connect->where(strtoupper($input["field"]),"like", "%".ucwords(strtolower($input["query"]))."%");
        } else if(!empty($lower)) {
          $connect->where(strtoupper($input["field"]),"like", "%".strtolower($input["query"])."%");
        }
      }
    }

    $count    = $connect->count();
    if (!empty($input['start']) || $input["start"] == '0') {
      if (!empty($input['limit'])) {
        $connect->skip($input['start'])->take($input['limit']);
      }
    }
    $result   = $connect->get();

    return ["result"=>$result, "count"=>$count];
  }

  public static function join($input) {
    if (!empty($input['special'])) {
      if ($input['special'] == "roleReff") {
        $a = "SELECT tr.*, tr2.REFF_NAME AS SERVICE, tr3.REFF_NAME AS STATUS FROM TR_ROLE tr, TM_REFF tr2, TM_REFF tr3 WHERE tr.ROLE_SERVICE = tr2.REFF_ID AND tr2.REFF_TR_ID = 1 AND tr.ROLE_STATUS = tr3.REFF_ID AND tr3.REFF_TR_ID = 3 ORDER BY tr.role_id DESC";
        $data = DB::connection("omuster")->select(DB::raw($a));
      }  else if($input['special'] == "bprpReff") {
        $a = "SELECT tr.*, tr2.REFF_NAME AS req_type, tr3.REFF_NAME AS STATUS FROM TX_HDR_BPRP tr, TM_REFF tr2, TM_REFF tr3 WHERE tr.BPRP_REQ_TYPE = tr2.REFF_ID AND tr2.REFF_TR_ID = 12 AND tr.BPRP_STATUS = tr3.REFF_ID AND tr3.REFF_TR_ID = 8 AND tr.BPRP_BRANCH_ID = 12 AND tr.BPRP_REQ_TYPE IN (1,2) ORDER BY tr.BPRP_ID DESC";
        $data = DB::connection("omcargo")->select(DB::raw($a));
      }
    }
    else {
    $connect = DB::connection($input["db"])->table($input["table"]);
    foreach ($input["join"] as $list) {
      $connect->join(strtoupper($list["table"]), strtoupper($list["field1"]), '=', strtoupper($list["field2"]));
    }

    if (!empty($input["selected"])) {
      $result  = $connect->select($input["selected"]);
    }

    if(!empty($input["orderby"][0])) {
    $in        = $input["orderby"];
    $connect->orderby(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["where"][0])) {
      $connect->where($input["where"]);
    }

    if(!empty($input["whereIn"][0])) {
    $in        = $input["whereIn"];
    $connect->whereIn(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["whereIn2"][0])) {
    $in        = $input["whereIn2"];
    $connect->whereIn(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["whereNotIn"][0])) {
    $in        = $input["whereNotIn"];
    $connect->whereNotIn(strtoupper($in[0]), $in[1]);
    }

    if (!empty($input["range"])) {
      $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
    }


    if (!empty($input["filter"])) {
    $search   = json_decode($input["filter"], TRUE);
    foreach ($search as $value) {
      if ($value["operator"] == "like")
        $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
      else if($value["operator"] == "eq")
        $connect->whereDate($value["property"],'=',$value["value"]);
      else if($value["operator"] == "gt")
        $connect->whereDate($value["property"],'>=',$value["value"]);
      else if($value["operator"] == "lt")
        $connect->whereDate($value["property"],'<=',$value["value"]);
      }
    }

    if (!empty($input["query"]) && !empty($input["field"])) {
      if (is_array($input["field"])) {
        foreach ($input["field"] as $field) {
          $connect->orwhere(strtoupper($field),"like", "%".strtoupper($input["query"])."%");
          $connect->orwhere(strtoupper($field),"like", "%".ucwords(strtolower($input["query"]))."%");
          $connect->orwhere(strtoupper($field),"like", "%".strtolower($input["query"])."%");
        }
      } else {
        $connect->orwhere(strtoupper($input["field"]),"like", "%".strtoupper($input["query"])."%");
        $connect->orwhere(strtoupper($input["field"]),"like", "%".ucwords($input["query"])."%");
        $connect->orwhere(strtoupper($input["field"]),"like", "%".strtolower($input["query"])."%");
      }
    }

    $count    = $connect->count();

    if (!empty($input['start']) || $input["start"] == '0') {
      if (!empty($input['limit'])) {
        $connect->skip($input['start'])->take($input['limit']);
      }
    }

    if (!empty($input["changeKey"])) {
      $result  = $connect->get();
      $data    = json_encode($result);
      $change  = str_replace($input["changeKey"][0], $input["changeKey"][1], $data);
      $data    = json_decode($change);
    } else {
      $data   = $connect->get();

      }
    }
    if (!empty($input['special'])) {
      return ["result"=>$data];
    } else {
      return ["result"=>$data, "count"=>$count];
    }
  }

  public static function whereQuery($input) {
    $connect   = \DB::connection($input["db"])->table($input["table"]);
    if(!empty($input["where"][0])) {
      $connect->where($input["where"]);
    }

    if (!empty($input["query"]) && !empty($input["field"])) {
      $connect->where(strtoupper($input["field"]),"like", "%".strtoupper($input["query"])."%");
    }

    if(!empty($input["whereIn"][0])) {
    $in        = $input["whereIn"];
    $connect->whereIn(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["orderby"][0])) {
    $in        = $input["orderby"];
    $connect->orderby(strtoupper($in[0]), $in[1]);
    }

    if (!empty($input["range"])) {
      $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
    }

    if (!empty($input["filter"])) {
    $search   = $input["filter"];
    foreach ($search as $value) {
      if ($value["operator"] == "like")
        $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
      else if($value["operator"] == "eq")
        $connect->whereDate($value["property"],'=',$value["value"]);
      else if($value["operator"] == "gt")
        $connect->whereDate($value["property"],'>=',$value["value"]);
      else if($value["operator"] == "lt")
        $connect->whereDate($value["property"],'<=',$value["value"]);
      }
    }


    if (!empty($input["selected"])) {
      $result  = $connect->select($input["selected"]);
    }

    $count     = $connect->count();
    if (!empty($input['start']) || $input["start"] == '0') {
      if (!empty($input['limit'])) {
        $connect->skip($input['start'])->take($input['limit']);
      }
    }

    if (!empty($input["changeKey"])) {
      $result  = $connect->get();
      $data    = json_encode($result);
      $change  = str_replace($input["changeKey"][0], $input["changeKey"][1], $data);
      $data  = json_decode($change);
    } else {
      $data  = $connect->get();
    }
    return ["result"=>$data, "count"=>$count];
  }

  public static function whereIn($input) {
    $connect   = \DB::connection($input["db"])->table($input["table"]);

    if(!empty($input["whereIn"][0])) {
    $in        = $input["whereIn"];
    $connect->whereIn(strtoupper($in[0]), $in[1]);
    }

    if(!empty($input["where"][0])) {
      $connect->where($input["where"]);
    }

    if(!empty($input["whereNotIn"][0])) {
    $in        = $input["whereNotIn"];
    $connect->whereNotIn(strtoupper($in[0]), $in[1]);
    }

    if (!empty($input["range"])) {
      $result  = $connect->whereBetween($input["range"][0],[$input["range"][1],$input["range"][2]]);
    }

    if (!empty($input["filter"])) {
    $search   = $input["filter"];
    foreach ($search as $value) {
      if ($value["operator"] == "like")
        $connect->Where(strtoupper($value["property"]),$value["operator"],"%".strtoupper($value["value"])."%");
      else if($value["operator"] == "eq")
        $connect->whereDate($value["property"],'=',$value["value"]);
      else if($value["operator"] == "gt")
        $connect->whereDate($value["property"],'>=',$value["value"]);
      else if($value["operator"] == "lt")
        $connect->whereDate($value["property"],'<=',$value["value"]);
      }
    }

    if (!empty($input["selected"])) {
      $result  = $connect->select(strtoupper($input["selected"]));
    }

    if(!empty($input["orderby"][0])) {
    $in        = $input["orderby"];
    $connect->orderby(strtoupper($in[0]), $in[1]);
    }

    $count    = $connect->count();
    if (!empty($input['start']) || $input["start"] == '0') {
      if (!empty($input['limit'])) {
        $connect->skip($input['start'])->take($input['limit']);
      }
    }
    $data      = $connect->get();
    return ["result"=>$data, "count"=>$count];
  }

  public static function saveheaderdetail($input) {
    $data    = $input["data"];
    $count   = count($input["data"]);
    $cek     = $input["HEADER"]["PK"];

    if(!empty($input["HEADER"]["SEQUENCE"])) {
    $sq      = $input["HEADER"]["SEQUENCE"];
    } else {
      $sq      = "Y";
    }

    foreach ($data as $data) {
      $val     = $input[$data];
      $connect  = DB::connection($val["DB"])->table($val["TABLE"]);
      if ($data == "HEADER") {
        $hdr   = json_decode(json_encode($val["VALUE"]), TRUE);
        if ($hdr[0][$cek] == '' || $sq == "N") {
          foreach ($val["VALUE"] as $value) {
            $insert       = $connect->insert([$value]);
          }
        } else {
          foreach ($val["VALUE"] as $value) {
            $insert       = $connect->where($cek,$hdr[0][$cek])->update($value);
          }
        }
        $header   = $connect->orderby($val["PK"], "desc")->first();
        $header   = json_decode(json_encode($header), TRUE);
      }
      else if($data == "FILE") {
        if ($hdr[0][$cek] != '') {
          $connect->where($val["FK"][0], $header[$val["FK"][1]]);
          $connect->delete();
        }
        foreach ($val["VALUE"] as $list) {
          if (isset($list["id"])) {
            unset($list["id"]);
          }
          $directory  = $val["DB"].'/'.$val["TABLE"].'/'.str_random(5).'/';
          $response   = FileUpload::upload_file($list, $directory);
          if (!empty($list["DOC_TYPE"])) {
            $addVal     = [$val["FK"][0]=>$header[$val["FK"][1]]]+['doc_no'=>$list["DOC_NO"],'doc_type'=>$list["DOC_TYPE"],'doc_name'=>$list["PATH"],'doc_path'=>$response['link']];
          } else {
            $addVal     = [$val["FK"][0]=>$header[$val["FK"][1]]]+['doc_no'=>$list["DOC_NO"],'doc_name'=>$list["PATH"],'doc_path'=>$response['link']];
          }
          if ($response['response'] == true) {
              $connect->insert([$addVal]);
            }
          }
      } else {
        if ($hdr[0][$cek] != '') {
          $connect->where($val["FK"][0], $header[$val["FK"][1]]);
          $connect->delete();
        }
        foreach ($val["VALUE"] as $value) {
          if (isset($value["id"])) {
            unset($value["id"]);
          }
          if (isset($value["DTL_OUT"])) {
            $value["DTL_OUT"] = str_replace("T"," ",$value["DTL_OUT"]);
            $value["DTL_OUT"] = str_replace(".000Z","",$value["DTL_OUT"]);
          }
          if (isset($value["DTL_IN"])) {
            $value["DTL_IN"] = str_replace("T"," ",$value["DTL_IN"]);
          }
          $addVal = [$val["FK"][0]=>$header[$val["FK"][1]]]+$value;
              if(empty($value["id"])) {
                $connect->insert([$addVal]);
              }
          }
        }
      }
    return ["result"=>"Save or Update Success", "header"=>$header];
  }

  public static function update($input){
    $connection = DB::connection($input["db"])->table($input["table"]);
    $connection->where($input["where"]);
    $connection->update($input["update"]);
    $data = $connection->get();
    return ["result"=>$data];
  }

  public static function save($input) {
    $parameter   = $input['parameter'];
    if (!empty($input["PK"])) {
      $cek         = DB::connection($input["db"])->table($input["table"])->where($input["PK"][0], $input["PK"][1])->get();
      if (!empty($cek)) {
        $cek       = DB::connection($input["db"])->table($input["table"])->where($input["PK"][0], $input["PK"][1])->delete();
        $connect   = \DB::connection($input["db"])->table($input["table"]);
        foreach ($parameter as $value) $connect->insert($parameter);
        return ["result"=>$parameter, "count"=>count($parameter)];
      } else {
        $connect   = \DB::connection($input["db"])->table($input["table"]);
        foreach ($parameter as $value) $connect->insert($parameter);
        return ["result"=>$parameter, "count"=>count($parameter)];
      }
    } else {
      $connect   = \DB::connection($input["db"])->table($input["table"]);
      foreach ($parameter as $value) $connect->insert($parameter);
      return ["result"=>$parameter];
    }
  }

  public static function delHeaderDetail($input) {
    $data    = $input["data"];
    $count   = count($input["data"]);
    $pk      = $input["HEADER"]["PK"][0];
    $pkVal   = $input["HEADER"]["PK"][1];
    foreach ($data as $data) {
      $val     = $input[$data];
      $connect  = DB::connection($val["DB"])->table($val["TABLE"]);
        if ($data == "HEADER") {
           $header   = $connect->where(strtoupper($pk), "like", strtoupper($pkVal))->get();
           $header   = json_decode(json_encode($header), TRUE);
        }

        else if($data == "FILE") {
          $fil     = [];
          $fk      = $val["FK"][0];
          $fkhdr   = $header[0][$val["FK"][1]];
          $detail  = $connect->where(strtoupper($fk), "like", "%".strtoupper($fkhdr)."%")->delete();
          }

        else {
          $fk      = $val["FK"][0];
          $fkhdr   = $header[0][$val["FK"][1]];
          $detail  = $connect->where(strtoupper($fk), "like",  "%".strtoupper($fkhdr)."%")->delete();
        }
    }

    $delHead = DB::connection($input["HEADER"]["DB"])->table($input["HEADER"]["TABLE"])->where(strtoupper($pk), "like", strtoupper($pkVal))->delete();
    return "Data Berhasil Dihapus";
  }

  public static function tanggalMasukKeluar($service, $req_no, $no) {
    if ($service == "DEL") {
        $header  = DB::connection('omcargo')->table('TX_HDR_'.$service)->where($service.'_NO', '=', $req_no)->get();
        $dtl      = DB::connection('omcargo')->table('TX_DTL_'.$service)->where('HDR_'.$service.'_ID', '=', $header[0]->del_id)->get();
        $date2  =date_create($dtl[$no]->dtl_out);
        $date1  =date_create($dtl[$no]->dtl_in);
        $count = date_diff($date1,$date2);
        echo date("d-m-y", strtotime($dtl[$no]->dtl_in))."<br>".date("d-m-y", strtotime($dtl[$no]->dtl_out))."<br>".$count->format("%d Hari");
    }
    else if ($service == "REC") {
        $dtlIn  = DB::connection('omcargo')->table('TX_HDR_'.$service)->where($service.'_NO', '=', $req_no)->get();
        $dtlOut = DB::connection('omcargo')->table('TX_DTL_'.$service)->where('HDR_'.$service.'_ID', '=', $dtlIn[0]->rec_id)->get();
        $date1  =date_create($dtlOut[$no]->dtl_in);
        $date2  =date_create($dtlIn[0]->rec_etd);
        $count = date_diff($date1,$date2);
        echo date("d-m-y", strtotime($dtlOut[$no]->dtl_in))."<br>".date("d-m-y", strtotime($dtlIn[0]->rec_etd))."<br>".$count->format("%d Hari");
    }
  }
}
