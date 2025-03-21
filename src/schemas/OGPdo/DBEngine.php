<?php

namespace ottimis\phplibs\schemas\OGPdo;

enum DBEngine: int
{
    case SQLSRV = 1;
    case PGSQL = 2;
    case MYSQL = 3;
    case SQLITE = 4;
    case ORACLE = 5;
}
