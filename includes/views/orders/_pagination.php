<div class="pagination">
    <?php
    $query_params = [];
    if ($status_filter !== 'pending') $query_params['status'] = $status_filter;
    if (!empty($branch_filter) && $branch_filter !== 'all') $query_params['branch'] = $branch_filter;

    if ($page > 1) {
        $query_params['page'] = $page - 1;
        echo '<a href="orders.php?' . http_build_query($query_params) . '">&laquo; Previous</a>';
    } else {
        echo '<span class="disabled">&laquo; Previous</span>';
    }

    $window = 2;
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == 1 || $i == $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
            if ($i == $page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                $query_params['page'] = $i;
                echo '<a href="orders.php?' . http_build_query($query_params) . '">' . $i . '</a>';
            }
        } elseif ($i == $page - $window - 1 || $i == $page + $window + 1) {
            echo '<span>...</span>';
        }
    }

    if ($page < $total_pages) {
        $query_params['page'] = $page + 1;
        echo '<a href="orders.php?' . http_build_query($query_params) . '">Next &raquo;</a>';
    } else {
        echo '<span class="disabled">Next &raquo;</span>';
    }
    ?>
</div>
