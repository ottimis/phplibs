<?php

namespace ottimis\phplibs;

use RuntimeException;

class OGVector
{
    private dataBasePgsql $db;

    public function __construct(string $dbName = "default")
    {
        $this->db = dataBasePgsql::getInstance($dbName);
    }

    /**
     * Enable pgvector extension on the database.
     */
    public function createExtension(): void
    {
        $this->db->query("CREATE EXTENSION IF NOT EXISTS vector");
    }

    /**
     * Add a vector column to a table.
     */
    public function addVectorColumn(string $table, string $column, int $dimensions): void
    {
        $this->db->query("ALTER TABLE $table ADD COLUMN IF NOT EXISTS $column vector($dimensions)");
    }

    /**
     * Create an index for vector similarity search.
     *
     * @param string $table Table name
     * @param string $column Vector column name
     * @param string $method Index method: 'ivfflat' or 'hnsw'
     * @param string $opclass Operator class: 'vector_cosine_ops', 'vector_l2_ops', 'vector_ip_ops'
     * @param array $options Additional index options (e.g., ['lists' => 100] for ivfflat)
     */
    public function createIndex(
        string $table,
        string $column,
        string $method = 'ivfflat',
        string $opclass = 'vector_cosine_ops',
        array $options = []
    ): void {
        $indexName = "{$table}_{$column}_idx";
        $withClause = '';
        if (!empty($options)) {
            $parts = [];
            foreach ($options as $k => $v) {
                $parts[] = "$k = $v";
            }
            $withClause = ' WITH (' . implode(', ', $parts) . ')';
        }
        $sql = "CREATE INDEX IF NOT EXISTS $indexName ON $table USING $method ($column $opclass)$withClause";
        $this->db->query($sql);
    }

    /**
     * Insert or update a record with a vector embedding.
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @param string $vectorColumn Name of the vector column
     * @param array $vector The embedding as a PHP array of floats
     * @param array $conflictKeys Conflict target columns for ON CONFLICT (default: ['id'])
     * @return array Result with success, id, affectedRows
     */
    public function upsert(
        string $table,
        array $data,
        string $vectorColumn,
        array $vector,
        array $conflictKeys = ['id']
    ): array {
        $db = $this->db;

        // Build column/value pairs for non-vector data
        $columns = [];
        $values = [];
        foreach ($data as $k => $v) {
            $columns[] = $k;
            if ($v === null) {
                $values[] = "NULL";
            } elseif ($v === 'now()') {
                $values[] = "now()";
            } elseif (is_bool($v)) {
                $values[] = $v ? '1' : '0';
            } elseif (is_array($v) || is_object($v)) {
                $values[] = "'" . $db->real_escape_string(json_encode($v, JSON_THROW_ON_ERROR)) . "'";
            } else {
                $values[] = "'" . $db->real_escape_string((string)$v) . "'";
            }
        }

        // Add vector column
        $columns[] = $vectorColumn;
        $values[] = "'" . $this->vectorToString($vector) . "'";

        $columnsSql = implode(", ", $columns);
        $valuesSql = implode(", ", $values);
        $conflictCols = implode(", ", $conflictKeys);

        // Build SET clause for ON CONFLICT using EXCLUDED
        $setClauses = [];
        foreach ($columns as $col) {
            $setClauses[] = "$col = EXCLUDED.$col";
        }
        $setSql = implode(", ", $setClauses);

        $sql = "INSERT INTO $table ($columnsSql) VALUES ($valuesSql) ON CONFLICT ($conflictCols) DO UPDATE SET $setSql RETURNING id";

        try {
            $r = $db->query($sql);
            if ($r) {
                return [
                    'success' => 1,
                    'id' => $db->insert_id(),
                    'affectedRows' => $db->affectedRows(),
                    'sql' => $sql,
                ];
            }
            return [
                'success' => 0,
                'error' => $db->error(),
                'sql' => $sql,
            ];
        } catch (\Exception $e) {
            return [
                'success' => 0,
                'error' => $e->getMessage(),
                'sql' => $sql,
            ];
        }
    }

    /**
     * Search by cosine similarity (most common for RAG/embeddings).
     * Returns records ordered by similarity (highest first).
     *
     * @param string $table Table name
     * @param string $vectorColumn Vector column name
     * @param array $queryVector The query embedding
     * @param int $limit Max results
     * @param array $where Optional WHERE conditions as associative array ['field' => 'value']
     * @param array $select Optional fields to select (default: ['*'])
     * @return array Results with similarity score
     */
    public function search(
        string $table,
        string $vectorColumn,
        array $queryVector,
        int $limit = 10,
        array $where = [],
        array $select = ['*']
    ): array {
        return $this->searchByMetric($table, $vectorColumn, $queryVector, '<=>', $limit, $where, $select);
    }

    /**
     * Search by L2 (Euclidean) distance.
     * Returns records ordered by distance (lowest first).
     */
    public function searchL2(
        string $table,
        string $vectorColumn,
        array $queryVector,
        int $limit = 10,
        array $where = [],
        array $select = ['*']
    ): array {
        return $this->searchByMetric($table, $vectorColumn, $queryVector, '<->', $limit, $where, $select);
    }

    /**
     * Search by inner product.
     * Returns records ordered by inner product (highest first).
     */
    public function searchInnerProduct(
        string $table,
        string $vectorColumn,
        array $queryVector,
        int $limit = 10,
        array $where = [],
        array $select = ['*']
    ): array {
        return $this->searchByMetric($table, $vectorColumn, $queryVector, '<#>', $limit, $where, $select);
    }

    private function searchByMetric(
        string $table,
        string $vectorColumn,
        array $queryVector,
        string $operator,
        int $limit,
        array $where,
        array $select
    ): array {
        $db = $this->db;
        $vecStr = $this->vectorToString($queryVector);
        $selectSql = implode(", ", $select);

        // Build similarity/distance expression
        if ($operator === '<=>') {
            $scoreSql = "1 - ($vectorColumn $operator '$vecStr') as similarity";
        } else {
            $scoreSql = "$vectorColumn $operator '$vecStr' as distance";
        }

        $whereSql = '';
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $k => $v) {
                $conditions[] = "$k = '" . $db->real_escape_string((string)$v) . "'";
            }
            $whereSql = "WHERE " . implode(" AND ", $conditions);
        }

        $sql = "SELECT $selectSql, $scoreSql FROM $table $whereSql ORDER BY $vectorColumn $operator '$vecStr' LIMIT $limit";

        try {
            $r = $db->query($sql);
            if (!$r) {
                return ['success' => false, 'error' => $db->error(), 'data' => []];
            }

            $data = [];
            while ($row = $db->fetchassoc()) {
                $data[] = $row;
            }
            return ['success' => true, 'data' => $data];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Convert a PHP array of floats to pgvector string format: '[0.1,0.2,0.3]'
     */
    private function vectorToString(array $vector): string
    {
        return '[' . implode(',', array_map('floatval', $vector)) . ']';
    }

    /**
     * Get the underlying database connection.
     */
    public function getDb(): dataBasePgsql
    {
        return $this->db;
    }
}