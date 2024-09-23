<?php
/**
 * Issues
 *
 * @package     CartPops\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>


<table class="form-table">
	<thead>
		<tr>
			<th>Issue</th>
			<th>What to do</th>
		</tr>
	</thead>
	<tbody>
		<?php if ( true === $this->has_issues ) : ?>
			<?php foreach ( $this->issue_list as $cpops_key => $cpops_issue ) : ?>
				<tr>
					<td class="cpops-issues-error">
						<div class="status-indicator status-indicator--danger status-indicator--sm  status-indicator--infinite">
							<div class="circle circle--animated circle-main"></div>
							<div class="circle circle--animated circle-secondary"></div>
							<div class="circle circle--animated circle-tertiary"></div>
						</div>
						<div class="cpops-issues-error__description">
							<p><?php echo wp_kses_post( $cpops_issue['error'] ); ?></p>
						</div>
					</td>
					<td><?php echo wp_kses_post( $cpops_issue['fix'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php elseif ( false === $this->has_issues ) : ?>
			<tr>
				<td class="cpops-issues-error">
					<div class="status-indicator status-indicator--sm js-status-indicator status-indicator--success">
						<div class="circle circle--animated circle-main"></div>
						<div class="circle circle--animated circle-secondary"></div>
						<div class="circle circle--animated circle-tertiary"></div>
					</div>
					<div class="cpops-issues-error__description">
						<p><?php esc_html_e( 'No issues were found.', 'cartpops' ); ?></p>
					</div>
				</td>
				<td><p><?php esc_html_e( 'No action needed.', 'cartpops' ); ?></p></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>


<a href="#" id="reset-issues" style="margin:30px 20px 0;" class="cpops-button secondary-button small-button">Refresh issues</a>

