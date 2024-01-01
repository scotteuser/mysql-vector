<?php

namespace MHz\MysqlVector\Tests;

use MHz\MysqlVector\VectorTable;
use PHPUnit\Framework\TestCase;

class VectorTableTest extends TestCase
{
    private $mysqli;
    private $vectorTable;
    private $dimension = 384;
    private $centroids = 20;
    private $testVectorAmount = 10000;

    protected function setUp(): void
    {
        $this->mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');

        // Check connection
        if ($this->mysqli->connect_error) {
            die("Connection failed: " . $this->mysqli->connect_error);
        }

        // Setup VectorTable for testing
        $this->vectorTable = new VectorTable('test_table', $this->dimension, $this->centroids);

        // Create required tables for testing
        $this->vectorTable->initialize($this->mysqli);
    }

    private function getRandomVectors($count, $dimension) {
        $vecs = [];
        for ($i = 0; $i < $count; $i++) {
            for($j = 0; $j < $dimension; $j++) {
                $vecs[$i][$j] = 2 * (mt_rand(0, 1000) / 1000) - 1;
            }
        }
        return $vecs;
    }

    public function testGetMetaTableName()
    {
        $metaTableName = $this->vectorTable->getMetaTableName();
        $this->assertEquals('vector_meta_test_table', $metaTableName);
    }

    public function testGetValuesTableName()
    {
        $valuesTableName = $this->vectorTable->getValuesTableName();
        $this->assertEquals('vector_values_test_table', $valuesTableName);
    }

    public function testUpsertSingle() {
        $this->mysqli->begin_transaction();

        $vecs = $this->getRandomVectors(1, $this->dimension);

        $ids = [];

        echo "Inserting 1 vector...\n";
        $time = microtime(true);
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }
        $time = microtime(true) - $time;
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";

        $this->assertEquals(count($vecs), $this->vectorTable->count($this->mysqli));
        $this->mysqli->commit();
        echo "wait";
    }

    public function testUpsert() {
        $this->mysqli->begin_transaction();

        $vecs = $this->getRandomVectors($this->testVectorAmount, $this->dimension);

        $ids = [];

        echo "Inserting $this->testVectorAmount vectors...\n";
        $time = microtime(true);
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }
        $time = microtime(true) - $time;
        echo "Elapsed time: " . sprintf('%02d:%02d:%02d', ($time/3600), ($time/60%60), $time%60) . "\n";

        $this->assertEquals(count($vecs), $this->vectorTable->count($this->mysqli));
        $results = $this->vectorTable->select($this->mysqli, $ids);
        $i = 0;
        foreach ($results as $result) {
            $this->assertEquals($vecs[$i], $result);
            $i++;
        }

        foreach ($results as $id => $result) {
            $this->vectorTable->upsert($this->mysqli, $vecs[0], $id);
            $results = $this->vectorTable->select($this->mysqli, [$id]);
            $this->assertEquals(1, count($results));
            $this->assertEquals($vecs[0], $results[$id]);
        }

        $this->mysqli->rollback();
    }

    public function testDot() {
        $this->mysqli->begin_transaction();

        $vecs = [];
        for($i = 0; $i < 2; $i++) {
            for($j = 0; $j < $this->dimension; $j++) {
                $vecs[$i][$j] = 0.5;
            }
        }

        $ids = [];
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $dotProduct = 0;
        for ($i = 0; $i < count($vecs[0]); $i++) {
            $dotProduct += $vecs[0][$i] * $vecs[1][$i];
        }

        $this->assertEquals($dotProduct, $this->vectorTable->dot($this->mysqli, $ids[0], $ids[1]));
        $this->assertEquals($dotProduct, $this->vectorTable->dot($this->mysqli, $ids[1], $vecs[0]));

        $this->mysqli->rollback();
    }

    public function testSelectAll() {
        $this->mysqli->begin_transaction();

        $vecs = $this->getRandomVectors(10, $this->dimension);
        foreach ($vecs as $vec) {
            $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $results = $this->vectorTable->selectAll($this->mysqli);
        $this->assertEquals(count($vecs), count($results));

        $i = 0;
        foreach ($results as $result) {
            $this->assertEquals($vecs[$i], $result['vector']);
            $i++;
        }
    }

    public function testSearch() {
        $this->mysqli->begin_transaction();

        // Insert $this->testVectorAmount random vectors
        $vecs = $this->getRandomVectors($this->testVectorAmount, $this->dimension);
        foreach ($vecs as $vec) {
            $this->vectorTable->upsert($this->mysqli, $vec);
        }

        // Let's insert a known vector
        $targetVector = array_fill(0, $this->dimension, 0.5);
        $this->vectorTable->upsert($this->mysqli, $targetVector);

        // Now, we search for this vector
        echo "Searching for vector...\n";
        $time = microtime(true);
        $results = $this->vectorTable->search($this->mysqli, $targetVector, 10);
        $time = microtime(true) - $time;
        // print time in format 00:00:00.000
        $elapsed = gmdate("H:i:s", $time) . '.' . str_pad(round($time - floor($time), 3) * 1000, 3, '0', STR_PAD_LEFT) . "\n";
        echo "Search completed in $elapsed";

        // At least the first result should be our target vector or very close
        $firstResultVector = $results[0]['vector'];
        $firstResultSimilarity = $results[0]['similarity'];

        $this->assertEquals($targetVector, $firstResultVector, "The most similar vector should be the target vector itself");
        $this->assertEqualsWithDelta(1.0, $firstResultSimilarity, 0.000000001, "The similarity of the most similar vector should be the highest possible value");

        $this->mysqli->rollback();
    }

    public function testDelete(): void {
        $this->mysqli->begin_transaction();

        $ids = [];
        // Insert $this->testVectorAmount random vectors
        $vecs = $this->getRandomVectors($this->testVectorAmount, $this->dimension);
        foreach ($vecs as $vec) {
            $ids[] = $this->vectorTable->upsert($this->mysqli, $vec);
        }

        $this->assertEquals(count($ids), $this->vectorTable->count($this->mysqli));

        foreach ($ids as $id) {
            $this->vectorTable->delete($this->mysqli, $id);
        }

        $this->assertEquals(0, $this->vectorTable->count($this->mysqli));

        $this->mysqli->rollback();
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up the database and close connection
        $mysqli = new \mysqli('localhost', 'root', '', 'mysql-vector');
        $vectorTable = new VectorTable('test_table', 3);
        $mysqli->query("DROP TABLE " . $vectorTable->getValuesTableName());
        $mysqli->query("DROP TABLE " . $vectorTable->getMetaTableName());
        $mysqli->query("DROP TABLE centroids_" . $vectorTable->getValuesTableName());
        $mysqli->query("DROP TABLE centroids_" . $vectorTable->getMetaTableName());
        $mysqli->close();
    }

    protected function tearDown(): void
    {
        $this->mysqli->query("DROP TABLE " . $this->vectorTable->getValuesTableName());
        $this->mysqli->query("DROP TABLE " . $this->vectorTable->getMetaTableName());
        $this->mysqli->query("DROP TABLE centroids_" . $this->vectorTable->getValuesTableName());
        $this->mysqli->query("DROP TABLE centroids_" . $this->vectorTable->getMetaTableName());
        $this->mysqli->close();
    }

}
