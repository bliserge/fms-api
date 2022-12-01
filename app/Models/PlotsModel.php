<?php

namespace App\Models;

use CodeIgniter\Model;

class PlotsModel extends Model {
    protected $table = 'plots';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id', 'ownerId', 'ownerCategory', 'area', 'location', 'permission', 'upi', 'upi_image'];
    protected $useTimestamps = true;
	protected $createdField  = 'created_at';
	protected $updatedField  = 'updated_at';
}

