<?php

namespace ottimis\phplibs\database_functions\schemas;

enum OPERATOR: string {
    case EQUALS = "=";
    case NOT_EQUALS = "!=";
    case GREATER_THAN = ">";
    case GREATER_THAN_OR_EQUALS = ">=";
    case LESS_THAN = "<";
    case LESS_THAN_OR_EQUALS = "<=";
    case LIKE = "LIKE";
    case NOT_LIKE = "NOT LIKE";
    case IN = "IN";
    case NOT_IN = "NOT IN";
    case IS_NULL = "IS NULL";
    case IS_NOT_NULL = "IS NOT NULL";
    case BETWEEN = "BETWEEN";
}
