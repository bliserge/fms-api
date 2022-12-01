<?php

namespace App\Models;

use CodeIgniter\Model;

class RequestsModel extends Model {
    protected $table = 'requests';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'ownerId', 'plot', 'data', 'status'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

