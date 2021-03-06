<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCfg\Visitor;
use PHPCfg\Operand;

use progpilot\Objects\MyOp;
use progpilot\Objects\MyDefinition;
use progpilot\Dataflow\Definitions;
use progpilot\Transformations\Php\BuildArrays;
use progpilot\Code\Opcodes;

class ArrayAnalysis
{


    public function __construct()
    {

    }

    public static function temporary_simple($context, $data, $defsearch, $def, $is_iterator = false, $is_assign = false)
    {
        $good_defs = [];

        if (($def->is_type(MyDefinition::TYPE_COPY_ARRAY) && ($defsearch->is_type(MyDefinition::TYPE_ARRAY) || $is_iterator)))
        {
            foreach ($def->get_copyarrays() as $def_copyarray)
            {
                $mydef_arr = $def_copyarray[0];
                $mydef_tmp = $def_copyarray[1];

                if (($mydef_arr === $defsearch->get_array_value()) || $is_iterator)
                    $good_defs[] = $mydef_tmp;
            }
        }

        // I'm looking for def[arr], I want to find def[arr], but I can have def (must be eliminated)
        else if ($defsearch->is_type(MyDefinition::TYPE_ARRAY))
        {
            if (($def->is_type(MyDefinition::TYPE_ARRAY)
                    && ($defsearch->get_array_value() === $def->get_array_value()) || $is_iterator) || $def->get_array_value() === "PROGPILOT_ALL_INDEX_TAINTED")
            {
                if ($def->get_array_value() === "PROGPILOT_ALL_INDEX_TAINTED")
                {
                    $defsearch->set_tainted(true);
                    //TaintAnalysis::set_tainted($context, $data, $defsearch, $def, $def->get_expr(), false);

                    if (ResolveDefs::get_visibility_from_instances($context, $data, $def))
                    {
                        ValueAnalysis::copy_values($defsearch, $def);
                        TaintAnalysis::set_tainted($defsearch, $def, $def->get_expr());
                    }
                }

                $good_defs[] = $def;
            }
        }

        // I'm looking for def, I want to find def, but I can have def[arr] (must be eliminated)
        else if (!$defsearch->is_type(MyDefinition::TYPE_ARRAY))
        {
            if (!$def->is_type(MyDefinition::TYPE_ARRAY) || $is_iterator)
                $good_defs[] = $def;
        }

        return $good_defs;
    }

    public static function funccall_before($context, $data, $myfunc, $myfunc_call, $instruction)
    {
        $nbparams = 0;
        $params = $myfunc->get_params();

        foreach ($params as $param)
        {
            if ($instruction->is_property_exist("argdef$nbparams"))
            {
                $newparam = clone $param;
                $myfunc_call->add_param($newparam);

                $defarg = $instruction->get_property("argdef$nbparams");
                $exprarg = $instruction->get_property("argexpr$nbparams");
                $newparam->set_last_known_values($defarg->get_last_known_values());

                ArrayAnalysis::copy_array_from_def($defarg, $defarg->get_array_value(), $newparam, false);

                $nbparams ++;
            }
        }

        $nbparams = 0;
        foreach ($params as $param)
        {
            $func_param = $myfunc_call->get_param($nbparams);

            if (!is_null($func_param))
            {
                $oldcopyarrays = $param->get_copyarrays();
                $old_flags = $param->get_type();
                $oldlastknownvalues = $param->get_last_known_values();

                $param->set_copyarrays($func_param->get_copyarrays());
                $param->set_last_known_values($func_param->get_last_known_values());
                $param->set_type($func_param->get_type());

                $func_param->set_copyarrays($oldcopyarrays);
                $func_param->set_last_known_values($oldlastknownvalues);
                $func_param->set_type($old_flags);
            }

            $nbparams ++;
        }
    }

    public static function funccall_after($context, $myfunc, $myfunc_call, $arr_funccall, $op_apr)
    {
        // remplacer ça par mexpr->get_assign_def() ?
        if ($op_apr->get_opcode() == Opcodes::DEFINITION)
        {
            $copytab = $op_apr->get_property("def");

            $originaltabs = $myfunc->get_return_defs();

            foreach ($originaltabs as $originaltab)
            {
                //$originaltab = $originaltabs[0];

                //$originaltab->print_stdout();
                /*
                            if ($originaltab->is_type(MyDefinition::TYPE_COPY_ARRAY))
                            {
                      echo "funccall_after <================= DEFINITION TYPE_COPY_ARRAY\n";
                                $copytab->add_type(MyDefinition::TYPE_COPY_ARRAY);
                                $copytab->set_copyarrays($originaltab->get_copyarrays());
                            }
                            else
                            {
                      echo "funccall_after <================= DEFINITION TYPE_COPY_ARRAY COPYARRAY 0\n";
                                if (count($originaltabs) >= 1)
                                {
                                */
                //ArrayAnalysis::copy_array($context, $myfunc->get_defs()->getoutminuskill($originaltab->get_block_id()), $originaltab, $arr_funccall, $copytab, $copytab->get_array_value());
                ArrayAnalysis::copy_array_from_def($originaltab, $arr_funccall, $copytab, $copytab->get_array_value());


                //}
                //}
            }
        }

        // on remet les paramètres originaux si d'autres appels
        $nbparams = 0;
        $params = $myfunc->get_params();
        foreach ($params as $param)
        {
            $func_param = $myfunc_call->get_param($nbparams);

            if (!is_null($func_param))
            {
                $oldcopyarrays = $func_param->get_copyarrays();
                $oldlastknownvalues = $func_param->get_last_known_values();
                $old_flags = $func_param->get_type();

                $func_param->set_copyarrays($param->get_copyarrays());
                $func_param->set_last_known_values($param->get_last_known_values());
                $func_param->set_type($param->get_type());

                $param->set_copyarrays($oldcopyarrays);
                $param->set_last_known_values($oldlastknownvalues);
                $param->set_type($old_flags);

                $nbparams ++;
            }
        }
        unset($params);
    }

    public static function copy_array($context, $data, $originaltab, $originalarr, $copytab, $copyarr)
    {
        if (!is_null($originaltab) && !is_null($copytab))
        {
            if ($originaltab->is_type(MyDefinition::TYPE_PROPERTY))
                $defs = ResolveDefs::select_properties(
                            $context,
                            $data,
                            $originaltab,
                            true);

            else
                $defs = ResolveDefs::select_definitions(
                            $context,
                            $data,
                            $originaltab,
                            true);

            foreach ($defs as $defa)
                ArrayAnalysis::copy_array_from_def($defa, $originalarr, $copytab, $copyarr);
        }
    }

    public static function copy_array_from_def($defa, $originalarr, $copytab, $copyarr)
    {
        if ($defa->is_type(MyDefinition::TYPE_COPY_ARRAY))
        {
            $copyarrays = $defa->get_copyarrays();

            foreach ($copyarrays as $value)
            {
                $arrvalue = $value[0];
                $defarr = $value[1];

                $extract = BuildArrays::extract_array_from_arr($arrvalue, $originalarr);
                if ($extract !== false)
                {
                    $extractbis = BuildArrays::build_array_from_arr($copyarr, $extract);

                    $copytab->add_copyarray($extractbis, $defarr);
                    $copytab->add_type(MyDefinition::TYPE_COPY_ARRAY);
                    if ($copytab->is_type(MyDefinition::TYPE_ARRAY))
                        $copytab->remove_type(MyDefinition::TYPE_ARRAY);
                    $copytab->set_array_value(false);

                    unset($defarr);
                }
            }
        }
        else
        {
            $extract = BuildArrays::extract_array_from_arr($defa->get_array_value(), $originalarr);

            // si on cherchait $copy = $array[11] ici il y a des arrays de type $array[11][quelquechose]
            // ou deuxieme cas
            // si on cherchait $copy = $arrays ici il y a des arrays de type $arrays[quelquechose]
            if ($extract !== false)
            {
                // si on a $copy[11] = $array[12] on veut $copy[11][12]
                if ($copyarr !== false)
                    $extract = BuildArrays::build_array_from_arr($copyarr, $extract);

                $copytab->add_copyarray($extract, $defa);
                $copytab->add_type(MyDefinition::TYPE_COPY_ARRAY);

                if ($copytab->is_type(MyDefinition::TYPE_ARRAY))
                    $copytab->remove_type(MyDefinition::TYPE_ARRAY);

                $copytab->set_array_value(false);
            }
        }
    }
}
