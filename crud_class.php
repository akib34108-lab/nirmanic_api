<?php

class crud_class
{

    private $host;
    private $username;
    private $password;
    private $database;
    public $conn;

    public function __construct($host = "localhost", $username = "root", $password = "", $database = "nirmanic")
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->connect();
    }

    private function connect()
    {
        $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
        if ($this->conn->connect_error) {
            die("Connection failed: " . $this->conn->connect_error);
        }
    }

    public function common_select(
        $table,
        $columns = "*",
        $where = [],
        $where_condition = "AND",
        $order_by = "",
        $sort_order = "ASC",
        $limit = "",
        $offset = ""
    ) {
        $result = [
            "status" => false,
            "data" => [],
            "message" => ""
        ];

        $sql = "SELECT $columns FROM $table";
        //$where=[id=>1, name=>'John'];
        if (!empty($where)) {
            $where_clauses = [];
            foreach ($where as $column => $value) {
                $where_clauses[] = "$table" . "." . "$column = '" . $this->conn->real_escape_string($value) . "'";
                //$where_clauses[] = "id='1'"
                //$where_clauses[] = "name='kamal'"
            }

            $sql .= " WHERE " . implode(" $where_condition ", $where_clauses);
            $sql .= " AND deleted_at IS NULL";
            // "SELECT * FROM users WHERE id='1' AND name='kamal'" and deleted_at IS NULL
        } else {
            $sql .= " WHERE deleted_at IS NULL";
        }


        if (!empty($order_by)) {
            $sql .= " ORDER BY $order_by $sort_order";
            // "SELECT * FROM users WHERE id='1' AND name='kamal' ORDER BY name ASC"
        }

        if (!empty($limit)) {
            $sql .= " LIMIT $limit";
            if (!empty($offset)) {
                $sql .= " OFFSET $offset";
            }

            // "SELECT * FROM users WHERE id='1' AND name='kamal' ORDER BY name ASC LIMIT 10 OFFSET 5"
        }
        // echo $sql; // Debugging line to check the generated SQL query
        $rs = $this->conn->query($sql);
        if ($rs->num_rows > 0) {
            $result["status"] = true;
            $result["message"] = "Records found";
            while ($row = $rs->fetch_object()) {
                $result["data"][] = $row;
            }
            return $result;
        } else {
            $result["message"] = "No records found";
            return $result;
        }
    }

    public function number_of_records($table)
    {
        $sql = "SELECT COUNT(*) as total FROM $table";
        $rs = $this->conn->query($sql);
        if ($rs->num_rows > 0) {
            $row = $rs->fetch_object();
            return $row->total;
        } else {
            return 0;
        }
    }

    public function common_query($sql)
    {
        $result = [
            "status" => false,
            "data" => [],
            "message" => ""
        ];
        //echo $sql; // Debugging line to check the generated SQL query
        $rs = $this->conn->query($sql);

        if ($rs->num_rows > 0) {
            $result["status"] = true;
            $result["message"] = "Records found";
            while ($row = $rs->fetch_object()) {
                $result["data"][] = $row;
            }
            return $result;
        } else {
            $result["message"] = "No records found";
            return $result;
        }
    }

    function getMainTable($sql)
    {
        $level = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {

            if ($sql[$i] == '(') {
                $level++;
            } elseif ($sql[$i] == ')') {
                $level--;
            }

            if ($level == 0 && strtoupper(substr($sql, $i, 5)) == 'FROM ') {

                $rest = trim(substr($sql, $i + 5));

                if (preg_match('/^`?([a-zA-Z0-9_]+)`?/i', $rest, $matches)) {
                    return $matches[1];
                }
            }
        }

        return "";
    }


    public function common_insert($table, $data)
    {
        $result = [
            "status" => false,
            "data" => [],
            "message" => ""
        ];

        $columns = implode(", ", array_keys($data));
        $values = implode("', '", array_map([$this->conn, 'real_escape_string'], array_values($data)));
        $sql = "INSERT INTO $table ($columns) VALUES ('$values')";
        //echo $sql; // Debugging line to check the generated SQL query
        if($this->conn->query($sql)){
            $result["status"] = true;
            $result["data"] = $this->conn->insert_id;
            $result["message"] = "Record inserted successfully";
            return $result;
        } else {
            $result["message"] = "Error: " . $this->conn->error;
            return $result;
        }
    }


    public function common_update($table, $data, $where = [], $where_condition = "AND")
    {
        $result = [
            "status" => false,
            "data" => [],
            "message" => ""
        ];


        $sql = "UPDATE $table SET ";
        $set_clauses = [];
        foreach ($data as $column => $value) {
            $set_clauses[] = "$column = '$value'";
        }
        $sql .= implode(", ", $set_clauses);

        if (!empty($where)) {
            $where_clauses = [];
            foreach ($where as $column => $value) {
                $where_clauses[] = "$column = '" . $this->conn->real_escape_string($value) . "'";
            }
            $sql .= " WHERE " . implode(" $where_condition ", $where_clauses);
        }
        if ($this->conn->query($sql)) {
            $result["status"] = true;
            $result["data"] = $this->conn->affected_rows;
            $result["message"] = "Record updated successfully";
            return $result;
        } else {
            $result["message"] = "Error: " . $this->conn->error;
            return $result;
        }
    }

    public function common_delete($table, $where = [], $where_condition = "AND")
    {
        $result = [
            "status" => false,
            "data" => [],
            "message" => ""
        ];

        $sql = "DELETE FROM $table";
        if (!empty($where)) {
            $where_clauses = [];
            foreach ($where as $column => $value) {
                $where_clauses[] = "$column = '" . $this->conn->real_escape_string($value) . "'";
            }
            $sql .= " WHERE " . implode(" $where_condition ", $where_clauses);
        }

        if ($this->conn->query($sql)) {
            $result["status"] = true;
            $result["data"] = $this->conn->affected_rows;
            $result["message"] = "Record deleted successfully";
            return $result;
        } else {
            $result["message"] = "Error: " . $this->conn->error;
            return $result;
        }
    }


    public function __destruct()
    {
        $this->conn->close();
    }
}

