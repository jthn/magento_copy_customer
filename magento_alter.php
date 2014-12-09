<?php

$mg = new magento_alter;

// To copy a customer, simply do something like this:
$id_from_old_account = 26362;

//$mg->copy_customer($id_from_old_account);

//$mg->copy_sales(26362 , 26937);

$mg->copy_guest_sale(39602);

// You'll find the id from the source account in the database in customer_entity.entity_id
// This script will copy a customer and all his orders - it assumes that the products of the two stores are the same

class magento_alter
{
    private $pdo_source = null;
    private $pdo_target = null;

    public $from_id     = null;
    public $to_id       = null;

    function __construct()
    {
        include('config_db.php');

        // Turn off foreign constraints
        $query = "set foreign_key_checks = 0";
        $this->pdo_source->query($query);

    }

    function __destruct()
    {
        // Turn key restraints back on
        $query = "set foreign_key_checks = 1";
        $this->pdo_source->query($query);
    }

    // Copy a customer from the source to the target
    public function copy_customer($cid)
    {
        // Should first check that both magento databases are the same

        // Get tables with a foreign key restraint to the customer_entity table
        $cust_restraint_tables = $this->get_customer_restraint_tables($this->pdo_source);

        // Get tables with the column customer_id that are not foreign key restraint tables
        $cust_id_tables        = array_diff_assoc( $this->get_customer_id_tables($this->pdo_source), $cust_restraint_tables  );

        // All tables for customer
        $tables                = array_merge( $cust_restraint_tables, $cust_id_tables );

        // Eliminate tables for orders
        $sales_tables = $this->get_sales_restraint_tables( $this->pdo_source );
        $sales_tables['sales_flat_order'] = 'customer_id';

        $tables = array_diff_key( $tables, $sales_tables );

        // Get a list of all the primary keys in each table
        $primary_keys = $this->get_primary_keys($this->pdo_source);

        // Find all tables with 2+ primary keys and get rid of them
        // If the program exited on this loop, a row has more than 1
        // primary key and this class is not set up to handle it yet
        foreach ( $tables as $table_name => $column_name )
        {
            if ( count( $primary_keys[$table_name] ) > 1 )
            {
                $this->log("Removing {$table_name} from tables array");

                $result = $this->pdo_source->query('select count(*) from ' . $table_name . ' ')->fetchColumn();

                $rowsInTableForCid = $this->pdo_source->query('select count(*) from ' . $table_name . ' where ' . $tables[$table_name] . ' = ' . $cid)->fetchColumn();

                if ( $rowsInTableForCid > 0 )
                {
                    echo $table_name . ' has ' . count($primary_keys[$table_name]) . ' primary keys and ' . $result . ' rows' . "\n";
                    echo 'There are ' . $rowsInTableForCid . ' rows with the customer id as the value of the column ' . $tables[$table_name] ;
                    echo 'exiting, line ' . __LINE__. "\n";exit;
                }

            }

        }

        // First insert the customer row into the customer_entity table
        $query_source = "select * from customer_entity where entity_id = " . $cid . ";";
        $st = $this->pdo_source->prepare($query_source);
        $st->execute();

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $this->log("Insert into customer_entity table for $cid");

            unset( $row['entity_id'] );

            $query_target = "insert into customer_entity (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";

            // Create parameters for ->execute() call
            $params = array();
            foreach ( $row as $key=>$value)
            {
                $params[] = $value;
            }

            $st = $this->pdo_target->prepare($query_target);
            $st->execute( $params );

            $target_cid = $this->pdo_target->lastInsertId();

            if($target_cid == 0) {
                $this->log("Something went wrong, customer not set!");
                $this->log($st->errorInfo());
                exit;
            }

            $this->log("Customer Inserted to target with entity_id = $target_cid");
        }

        // These are tables we can work with
        foreach ($tables as $table_name => $column_name)
        {
            $this->log($table_name . " " . $column_name);

            // Get rows from source where the source customer id is present
            $query_source = "select * from " . $table_name . " where " . $column_name . " = " . $cid . ";";

            $st = $this->pdo_source->prepare($query_source);
            $st->execute();

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row)
            {
                // Get rid of the primary key
                unset($row[$primary_keys[$table_name][0]]);

                // Replace foreign restraint with the new client id
                $row[$column_name] = $target_cid;

                $query_target = "insert into " . $table_name . " (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";

                $this->log($query_target);

                // Create parameters for ->execute() call
                $params = array();
                foreach ( $row as $key=>$value)
                {
                    $params[] = $value;
                }

                $this->log(implode($params, ','));

                $st = $this->pdo_target->prepare($query_target);
                $st->execute( $params );

                $last_insert_id = $this->pdo_target->lastInsertId();
                $this->log("$table_name => last insert id: $last_insert_id");
            }
        }

        echo "DONE copying customer\n executing: \n \$mg->copy_sales(". $cid ." , " . $target_cid . ");\n";
        $this->copy_sales( $cid, $target_cid );
    }


    public function copy_sales($old_cid, $new_cid)
    {
        $cid = $old_cid;

        // Get tables with a foreign key restraint to the sales_flat_order table
        $tables = $this->get_sales_restraint_tables( $this->pdo_source );

        $this->log($tables);

        // Get a list of all the primary keys in each table
        $primary_keys = $this->get_primary_keys($this->pdo_source);

        // Find all tables with 2+ primary keys and get rid of them
        // If the program exited on this loop, a row has more than 1
        // primary key and this class is not set up to handle it yet
        foreach ( $tables as $table_name=>$column_name )
        {
            if ( count( $primary_keys[$table_name] ) > 1 )
            {
                $rowsInTableForCid = $this->pdo_source->query('select count(*) from ' . $table_name . ' where ' . $tables[$table_name] . ' = ' . $cid)->fetchColumn();

                if ( $rowsInTableForCid > 0 )
                {
                    echo $table_name . ' has ' . count($primary_keys[$table_name]) . ' primary keys and ' . $result . ' rows' . "\n";
                    echo 'There are ' . $rowsInTableForCid . ' rows with the customer id as the value of the column ' . $tables[$table_name] ;
                    echo 'exiting, line ' . __LINE__. "\n";exit;
                }
            }
        }

        // Find all orders for this customer
        $order_numbers = array();
        $query_source = "select entity_id from sales_flat_order where customer_id = " . $old_cid;
        foreach ( $this->pdo_source->query($query_source) as $row )
        {
            $order_numbers[] = $row['entity_id'];
        }

        $this->log("Preparing to load orders for customer $old_cid => (" . implode($order_numbers, ',') . ')');

        foreach ( $order_numbers as $order_id )
        {
            // First insert the order row into the sales_flat_order table
            $query_source = "select * from sales_flat_order where entity_id = " . $order_id . ";";

            $st = $this->pdo_source->prepare($query_source);
            $st->execute();

            foreach ( $st->fetchAll(PDO::FETCH_ASSOC) as $row )
            {
                // Unset the primary key
                $source_order_id = $row['entity_id'];
                unset( $row['entity_id'] );
                unset( $row['increment_id'] );
                $row['customer_id'] = $new_cid;

                // Grab an increment_id
                $query_increment_id = "select max(increment_id)+1 from sales_flat_order";
                $increment_id = $this->pdo_target->query($query_increment_id)->fetchColumn();
                $row['increment_id'] = $increment_id;

                $query_target = "insert into sales_flat_order (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";

                $params = array();
                foreach ( $row as $key=>$value)
                {
                    $params[] = $value;
                }

                $st = $this->pdo_target->prepare($query_target);
                $st->execute( $params );

                $target_order_id = $this->pdo_target->lastInsertId();

                if($target_order_id == 0) {
                    $this->log("Something went wrong, customer not set!");
                    $this->log($st->errorInfo());
                    exit;
                }

                $this->log("Order Inserted to target with entity_id = $target_order_id");

                // Return the last_insert_id and use it for the other tables

                $this->log("$new_cid-target db customer_id $target_order_id-target database order id");

                // These are tables we can work with
                foreach ( $tables as $table_name=>$column_name )
                {
                    // Get rows from source where the source order id is present
                    $this->log($table_name . ' ' . $column_name);
                    $query_source = "select * from " . $table_name . " where " . $column_name . " = " . $source_order_id . ";";

                    $this->log($query_source);

                    $st = $this->pdo_source->prepare($query_source);
                    $st->execute();

                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row2)
                    {
                        // Get rid of the primary key
                        unset( $row2[$primary_keys[$table_name][0]] );

                        // Replace foreign restraint with the new client id
                        $row2[$column_name] = $target_order_id;

                        $query_target = "insert into " . $table_name . " (" . implode(',', array_keys($row2)) . ") values (" . implode(',', array_fill(0, count($row2), '?')) . ")";

                        $this->log($query_target);

                        // Create parameters for ->execute() call
                        $params = array();
                        foreach ( $row2 as $key=>$value)
                        {
                            $params[] = $value;
                        }

                        $this->log(implode($params, ','));

                        $st = $this->pdo_target->prepare($query_target);
                        $st->execute( $params );

                        $last_insert_id = $this->pdo_target->lastInsertId();

                        $this->log("Last insert id: $last_insert_id");
                    }

                }

            }

            echo "DONE WITH order id " . $target_order_id . "\n";

        }

        echo "DONE\n";
    }

    // Find tables that have foreign restraint to the $table in question
    public function get_restraint_tables($table, $pdo)
    {
        $query = "select table_name, column_name, referenced_table_name, referenced_column_name from information_schema.key_column_usage where referenced_table_name = '" . $table . "';";

        $tables = array();
        foreach( $pdo->query($query) as $row )
        {
            $tables[$row['table_name']] = $row['column_name'];

            if ( 'entity_id' != $row['referenced_column_name'] || $table != $row['referenced_table_name'] )
            {
                echo 'Line: ' . __LINE__ . ' refers to ' . $row['referenced_table_name'] . '.' . $row['referenced_column_name'] . ' instead of ' . $table . '.entity_id' . "\n";die();
            }
        }
        return $tables;
    }

    public function get_customer_restraint_tables($pdo)
    {
        return $this->get_restraint_tables( 'customer_entity', $pdo );
    }

    public function get_sales_restraint_tables($pdo)
    {
        return $this->get_restraint_tables( 'sales_flat_order', $pdo );
    }

    public function get_customer_id_tables($pdo)
    {
        // Find all tables with the column customer_id
        $query = "select table_name, column_name from information_schema.columns where column_name = 'customer_id'";
        $tables2 = array();
        foreach( $pdo->query($query) as $row )
        {
            $tables2[$row['table_name']] = $row['column_name'];
        }
        return $tables2;
    }

    public function get_primary_keys($pdo)
    {
        $query = "select distinct(table_name), column_name from information_schema.key_column_usage where CONSTRAINT_NAME = 'PRIMARY'";
        $tables2 = array();
        foreach( $pdo->query($query) as $row )
        {
            $tables2[$row['table_name']][] = $row['column_name'];
        }
        return $tables2;
    }

    public function log($message)
    {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        } else {
            $message = (string) $message;
            $message = substr($message, -1) == PHP_EOL ? $message : $message . PHP_EOL;
        }
        echo $message;
    }
}

