<?php
/**
 * The activity log as a WordPress list table.
 *
 * The log answers "what did the agent do to my site?", and a flat list of
 * the last hundred calls only answered it for the last hour of work. This
 * filters by tool, result, user and page, pages through everything that
 * is kept, shows times in the site's timezone, and links each save to the
 * revision it left, where core's compare screen shows the change.
 *
 * Read-only on purpose: no bulk actions, no delete. The log is the record
 * of what happened; pruning it is the retention setting's job, not a
 * checkbox somebody ticks.
 *
 * Loaded only when the Activity tab renders.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table over {prefix}wpmcp_log.
 */
class WPMCP_Log_Table extends WP_List_Table {

	/**
	 * Entries per page.
	 */
	const PER_PAGE = 25;

	/**
	 * Filters in force, read once from the request.
	 *
	 * @var array{tool: string, operation: string, user: int, post: int, s: string}
	 */
	private $filters;

	/**
	 * Set up the table.
	 *
	 * @param array|null $filters Query arguments; read from the request when null.
	 */
	public function __construct( $filters = null ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
		$this->filters = self::sanitize_filters( null === $filters ? $_GET : $filters ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
	}

	/**
	 * Query arguments the filters travel in. Prefixed, because "post",
	 * "user" and "s" mean something to other parts of wp-admin.
	 */
	const QUERY_ARGS = array(
		'tool'      => 'log_tool',
		'operation' => 'log_result',
		'user'      => 'log_user',
		'post'      => 'log_post',
		's'         => 's',
	);

	/**
	 * The filters this table knows, cleaned. Anything else is dropped.
	 *
	 * @param array $raw Request (query argument names) or given values.
	 * @return array{tool: string, operation: string, user: int, post: int, s: string}
	 */
	public static function sanitize_filters( array $raw ) {
		$get = function ( $key ) use ( $raw ) {
			$name = self::QUERY_ARGS[ $key ];
			return isset( $raw[ $name ] ) && is_scalar( $raw[ $name ] ) ? (string) wp_unslash( $raw[ $name ] ) : '';
		};

		$operation = sanitize_key( $get( 'operation' ) );

		return array(
			'tool'      => substr( preg_replace( '#[^a-z0-9/_-]#', '', strtolower( $get( 'tool' ) ) ), 0, 64 ),
			'operation' => array_key_exists( $operation, self::operation_filters() ) ? $operation : '',
			'user'      => max( 0, (int) $get( 'user' ) ),
			'post'      => max( 0, (int) $get( 'post' ) ),
			's'         => trim( sanitize_text_field( $get( 's' ) ) ),
		);
	}

	/**
	 * What the result filter offers.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function operation_filters() {
		return array(
			'saved'    => __( 'Saved', 'wp-mcp-connector-plus' ),
			'dry_run'  => __( 'Dry runs', 'wp-mcp-connector-plus' ),
			'rejected' => __( 'Rejected', 'wp-mcp-connector-plus' ),
		);
	}

	/**
	 * WHERE clause and its arguments for a set of filters.
	 *
	 * Every value travels as a placeholder argument; the clause itself is
	 * built from fixed strings only.
	 *
	 * @param array $filters Cleaned filters.
	 * @return array{0: string, 1: array} Clause (with leading WHERE, or empty) and arguments.
	 */
	public static function where( array $filters ) {
		global $wpdb;

		$clauses = array();
		$args    = array();

		if ( '' !== $filters['tool'] ) {
			$clauses[] = 'ability = %s';
			$args[]    = $filters['tool'];
		}
		if ( $filters['user'] > 0 ) {
			$clauses[] = 'user_id = %d';
			$args[]    = $filters['user'];
		}
		if ( $filters['post'] > 0 ) {
			$clauses[] = 'post_id = %d';
			$args[]    = $filters['post'];
		}
		if ( 'dry_run' === $filters['operation'] ) {
			$clauses[] = 'dry_run = 1';
		} elseif ( 'rejected' === $filters['operation'] ) {
			$clauses[] = "dry_run = 0 AND operation = 'rejected'";
		} elseif ( 'saved' === $filters['operation'] ) {
			// A real write: not a dry run, not refused, and one that names
			// what it did. Reads leave the operation empty.
			$clauses[] = "dry_run = 0 AND operation <> '' AND operation <> 'rejected'";
		}
		if ( '' !== $filters['s'] ) {
			$clauses[] = 'summary LIKE %s';
			$args[]    = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
		}

		return array( $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '', $args );
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'time'      => __( 'Time', 'wp-mcp-connector-plus' ),
			'user'      => __( 'User', 'wp-mcp-connector-plus' ),
			'tool'      => __( 'Tool', 'wp-mcp-connector-plus' ),
			'post'      => __( 'Content', 'wp-mcp-connector-plus' ),
			'operation' => __( 'Result', 'wp-mcp-connector-plus' ),
			'summary'   => __( 'Summary', 'wp-mcp-connector-plus' ),
		);
	}

	/**
	 * The column that stays visible on small screens.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'time';
	}

	/**
	 * Load the page of entries the filters and the page number ask for.
	 */
	public function prepare_items() {
		global $wpdb;

		$table = wpmcp_audit_table();
		list( $where, $args ) = self::where( $this->filters );

		$page   = max( 1, (int) $this->get_pagenum() );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$rows_sql  = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; values are placeholders, the clause is fixed strings.
		$total       = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$this->items = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( self::PER_PAGE, $offset ) ) ) );
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		// One query each for the posts, revisions and users on this page,
		// instead of one per row and column.
		$post_ids = array();
		$user_ids = array();
		foreach ( $this->items as $item ) {
			if ( (int) $item->post_id > 0 ) {
				$post_ids[] = (int) $item->post_id;
			}
			if ( (int) $item->revision_id > 0 ) {
				$post_ids[] = (int) $item->revision_id;
			}
			if ( (int) $item->user_id > 0 ) {
				$user_ids[] = (int) $item->user_id;
			}
		}
		if ( $post_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( $post_ids ), false, false );
		}
		if ( $user_ids && function_exists( 'cache_users' ) ) {
			cache_users( array_unique( $user_ids ) );
		}
	}

	/**
	 * What to say when there is nothing to show.
	 */
	public function no_items() {
		if ( $this->has_filters() ) {
			esc_html_e( 'No entries match these filters.', 'wp-mcp-connector-plus' );
			return;
		}
		esc_html_e( 'Nothing logged yet. Every call the agent makes appears here.', 'wp-mcp-connector-plus' );
	}

	/**
	 * Are any filters in force?
	 *
	 * @return bool
	 */
	private function has_filters() {
		return '' !== $this->filters['tool'] || '' !== $this->filters['operation'] || $this->filters['user'] > 0 || $this->filters['post'] > 0 || '' !== $this->filters['s'];
	}

	/**
	 * Link to this view with some filters changed, paging reset.
	 *
	 * @param array $changes Filter => value; '' or 0 removes it.
	 * @return string
	 */
	private function filter_url( array $changes ) {
		$query = array();
		foreach ( array_merge( $this->filters, $changes ) as $key => $value ) {
			if ( $value ) {
				$query[ self::QUERY_ARGS[ $key ] ] = $value;
			}
		}

		return wpmcp_admin_url( 'activity', $query );
	}

	/**
	 * Filter dropdowns above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $wpdb;
		$table = wpmcp_audit_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, no user input.
		$tools    = (array) $wpdb->get_col( "SELECT DISTINCT ability FROM {$table} ORDER BY ability" );
		$user_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0" ) );
		// phpcs:enable
		?>
		<div class="alignleft actions wpmcp-log-filters">
			<label class="screen-reader-text" for="wpmcp-filter-tool"><?php esc_html_e( 'Filter by tool', 'wp-mcp-connector-plus' ); ?></label>
			<select name="log_tool" id="wpmcp-filter-tool">
				<option value=""><?php esc_html_e( 'All tools', 'wp-mcp-connector-plus' ); ?></option>
				<?php foreach ( $tools as $tool ) : ?>
					<option value="<?php echo esc_attr( $tool ); ?>" <?php selected( $this->filters['tool'], $tool ); ?>><?php echo esc_html( wpmcp_short_tool_name( $tool ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wpmcp-filter-operation"><?php esc_html_e( 'Filter by result', 'wp-mcp-connector-plus' ); ?></label>
			<select name="log_result" id="wpmcp-filter-operation">
				<option value=""><?php esc_html_e( 'All results', 'wp-mcp-connector-plus' ); ?></option>
				<?php foreach ( self::operation_filters() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->filters['operation'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wpmcp-filter-user"><?php esc_html_e( 'Filter by user', 'wp-mcp-connector-plus' ); ?></label>
			<select name="log_user" id="wpmcp-filter-user">
				<option value="0"><?php esc_html_e( 'All users', 'wp-mcp-connector-plus' ); ?></option>
				<?php foreach ( $user_ids as $user_id ) : ?>
					<option value="<?php echo (int) $user_id; ?>" <?php selected( $this->filters['user'], $user_id ); ?>><?php echo esc_html( $this->user_name( $user_id ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wpmcp-filter-post"><?php esc_html_e( 'Filter by post ID', 'wp-mcp-connector-plus' ); ?></label>
			<input type="number" min="1" name="log_post" id="wpmcp-filter-post"
				value="<?php echo $this->filters['post'] ? (int) $this->filters['post'] : ''; ?>"
				placeholder="<?php esc_attr_e( 'Post ID', 'wp-mcp-connector-plus' ); ?>" />

			<?php submit_button( __( 'Filter', 'wp-mcp-connector-plus' ), '', 'filter_action', false, array( 'id' => 'wpmcp-filter-submit' ) ); ?>

			<?php if ( $this->has_filters() ) : ?>
				<a class="button-link" href="<?php echo esc_url( wpmcp_admin_url( 'activity' ) ); ?>"><?php esc_html_e( 'Clear filters', 'wp-mcp-connector-plus' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A user's display name, or a placeholder for one that is gone.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_name( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user
			? $user->display_name
			/* translators: %d: user ID */
			: sprintf( __( 'Deleted user #%d', 'wp-mcp-connector-plus' ), (int) $user_id );
	}

	/**
	 * Time, in the site's timezone; the UTC value in the markup.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_time( $item ) {
		$at = strtotime( $item->created_at . ' UTC' );
		if ( ! $at ) {
			return esc_html( $item->created_at );
		}
		return sprintf(
			'<time datetime="%s" title="%s">%s</time>',
			esc_attr( gmdate( 'c', $at ) ),
			esc_attr( $item->created_at . ' UTC' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) )
		);
	}

	/**
	 * Who made the call; links to the log of that user.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_user( $item ) {
		$user_id = (int) $item->user_id;
		if ( $user_id <= 0 ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'No user (automatic)', 'wp-mcp-connector-plus' ) . '</span>';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->filter_url( array( 'user' => $user_id ) ) ),
			esc_html( $this->user_name( $user_id ) )
		);
	}

	/**
	 * The tool, as the agent calls it; links to the log of that tool.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_tool( $item ) {
		return sprintf(
			'<a href="%s"><code>%s</code></a>',
			esc_url( $this->filter_url( array( 'tool' => $item->ability ) ) ),
			esc_html( wpmcp_short_tool_name( $item->ability ) )
		);
	}

	/**
	 * The content touched: title to the editor, ID to its log.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_post( $item ) {
		$post_id = (int) $item->post_id;
		if ( $post_id <= 0 ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		$post  = get_post( $post_id );
		$title = $post ? get_the_title( $post ) : '';
		$title = '' !== $title ? $title : sprintf( '#%d', $post_id );
		$edit  = $post ? get_edit_post_link( $post_id ) : '';

		$name = $edit
			? sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html( $title ) )
			: esc_html( $title ) . ( $post ? '' : ' <em>' . esc_html__( '(deleted)', 'wp-mcp-connector-plus' ) . '</em>' );

		return $name . sprintf(
			'<br><a class="wpmcp-muted" href="%s" aria-label="%s">#%d</a>',
			esc_url( $this->filter_url( array( 'post' => $post_id ) ) ),
			/* translators: %d: post ID */
			esc_attr( sprintf( __( 'Show the log of post %d', 'wp-mcp-connector-plus' ), $post_id ) ),
			$post_id
		);
	}

	/**
	 * What came of the call: saved (and how), dry run, or rejected.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_operation( $item ) {
		$operation = (string) $item->operation;

		if ( ! empty( $item->dry_run ) ) {
			$badge = sprintf( '<span class="wpmcp-op is-dry-run">%s</span>', esc_html__( 'Dry run', 'wp-mcp-connector-plus' ) );
			return $badge . ( '' !== $operation ? ' <code>' . esc_html( $operation ) . '</code>' : '' );
		}
		if ( 'rejected' === $operation ) {
			return sprintf( '<span class="wpmcp-op is-rejected">%s</span>', esc_html__( 'Rejected', 'wp-mcp-connector-plus' ) );
		}
		if ( '' === $operation ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}
		return sprintf(
			'<span class="wpmcp-op is-saved">%s</span> <code>%s</code>',
			esc_html__( 'Saved', 'wp-mcp-connector-plus' ),
			esc_html( $operation )
		);
	}

	/**
	 * The summary, and the way to the change where one was saved.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	protected function column_summary( $item ) {
		$html        = esc_html( $item->summary );
		$revision_id = (int) $item->revision_id;

		if ( $revision_id > 0 && get_post( $revision_id ) ) {
			$html .= sprintf(
				'<br><a href="%s">%s</a>',
				esc_url( add_query_arg( 'revision', $revision_id, admin_url( 'revision.php' ) ) ),
				esc_html__( 'Compare revisions', 'wp-mcp-connector-plus' )
			);
		}

		return $html;
	}

	/**
	 * Fallback for columns without their own method.
	 *
	 * @param object $item        Log row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}
}
