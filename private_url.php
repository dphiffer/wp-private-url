<?php
/*
Plugin Name: Private URL
Plugin URI: http://jamesclarke.info/projects/private-url
Description: Create publicly accessible URLs for your private posts
Version: 1.0.2
Author: James Clarke
Author URI: http://jamesclarke.info

License:

	Copyright 2007 James Clarke (email: james@jamesclarke.info)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/


class prvurl {
	var $pass_tag = 'prvpass';
	var $salt = '';
	var $salt_key = 'post_salt';
	var $link_base = '';

	function prvurl() {
		add_action('activate_'.$this->plugin_basename(__FILE__), array(&$this, 'activate'));
		add_action('init', array(&$this, 'flush_rewrite_rules'));
		add_action('generate_rewrite_rules', array(&$this, 'generate_rewrite_rules'));
		add_filter('query_vars', array(&$this, 'add_query_vars'));
		add_filter('posts_where', array(&$this, 'posts_where'));
		add_action('template_redirect', array(&$this, 'template_redirect'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('load-post.php', array(&$this, 'load_post'));
		add_action('save_post', array(&$this, 'save_post'));
		add_filter('posts_results', array(&$this, 'posts_results'));
		add_filter('redirect_canonical', array(&$this, 'redirect_canonical'), 10, 2);
		//setup some defaults incase we have no options
		if (!$this->salt = get_option('prvurl_salt'))
			$this->salt = 'this privacy is not worth its salt';
		if (!$this->link_base = get_option('prvurl_path'))
			$this->link_base = 'private';
	}

	function activate() {
		if (!get_option('prvurl_path'))
			add_option('prvurl_path', 'private', '', 'no');
		$salt = get_option('prvurl_salt');
		if (! $salt || $salt == 'no one need ever know') {
			$salt = wp_salt();
			// Use the global auth salt
			if ($salt == 'put your unique phrase here') {
				// Unless it hasn't been assigned, just load up some new salt
				$rsp = wp_remote_get('https://api.wordpress.org/secret-key/1.1/salt/');
				$body = wp_remote_retrieve_body($rsp);
				if (preg_match("/^define\('AUTH_KEY',\s*'(.+?)'/", $body, $matches)) {
					$salt = $matches[1];
				} else {
					$salt = 'this privacy is not worth its salt';
				}
			}
			update_option('prvurl_salt', $salt, '', 'no');
			$this->salt = $salt;
		}
		return true;
	}


	//Required so our new rewrite rules are registered
	function flush_rewrite_rules() {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}

	//This generates the rules to handle /$link_base/%post_id%/$pass_tag/
	function generate_rewrite_rules($wp_rewrite) {
		$new_rules = array($this->link_base. '/([^/]+)/([^/]+)' => 'index.php?p='.$wp_rewrite->preg_index(1).'&'.$this->pass_tag .'='.$wp_rewrite->preg_index(2));
		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
	}

	//Add our query variable for later
	function add_query_vars($vars) {
		$vars[] = $this->pass_tag;
		$vars[] = $this->id_tag;
		return $vars;
	}

	//Add our where clause to get private posts too.  We also get published
	//posts incase an old private post becomes published.
	function posts_where($where) {
		$pass = get_query_var('p');
		$id = get_query_var($this->id_tag);
		if (empty($pass) || empty($id))
			return $where;
		$where = "AND (post_status = 'private' OR post_status = 'publish') AND ID = $id";
		return $where;
	}


	//This performs a hash of the data combined with the salt
	function generate_key($data, $salt) {
		if ( function_exists('hash_hmac') ) {
			$key = hash_hmac('md5', $data, $salt);
		} else {
			$key = md5($data . $salt);
		}
		return $key;
	}

	//The logic to check the hash/password is correct.
	//If correct we'll set the post as published (not in the db though!)
	//This might not be the best way to do it though?
	function posts_results($posts) {
		$id = get_query_var('p');
		$pass = get_query_var($this->pass_tag);
		if (empty($id) || empty($pass)) {
			return $posts;
		}
		$post_salt = get_post_meta($posts[0]->ID, $this->salt_key, true);
		if ($post_salt == '')
			$post_salt = $this->salt;
		$data = $id;
		$key = $this->generate_key($data, $post_salt);
		if (strcmp($key, $pass) != 0) {
			return $posts;
		}
		$posts[0]->post_status = 'publish';
		return $posts;
	}

	function template_redirect() {
		global $post;
		// Make sure private pages aren't cached publicly
		if (empty($post))
			return;
		header('Cache-Control: private');
		header('Pragma: no-cache');
	}

	function load_post() {
		// This narrows our scope to when post.php loads
		add_filter('post_link', array(&$this, 'modify_post_link'));
	}

	function save_post($post_id) {
		$post_salt = $this->salt;
		update_post_meta($post_id, $this->salt_key, $post_salt);
	}

	function redirect_canonical($redirect_url, $requested_url) {
		if (! preg_match('/p=(\d+)/', $requested_url, $matches)) {
			$path = get_option('prvurl_path');
			if (! preg_match("/$path\/(\d+)\//", $requested_url, $matches)) {
				return $redirect_url;
			}
		}
		$post_id = $matches[1];
		$post = get_post($post_id);
		if ($post->post_status == 'private') {
			return false;
		}
		return $redirect_url;
	}

	function modify_post_link($permalink, $post, $leavename) {
		global $post;
		if ($post->post_status != 'private') {
			return $permalink;
		}
		$post_salt = get_post_meta($posts[0]->ID, $this->salt_key, true);
		if ($post_salt == '')
			$post_salt = $this->salt;
		$data = $post->ID;
		$key = $this->generate_key($data, $post_salt);
		$theurl = get_bloginfo('url') . '/'.$this->link_base.'/'.$post->ID.'/'.$key;
		return $theurl;
	}

	function plugin_basename($file) {
		$file = preg_replace('/^.*wp-content[\\\\\/]plugins[\\\\\/]/', '', $file);
		return $file;
	}

	function admin_menu() {
		add_options_page('Private URL Options', 'Private URL', 'manage_options', $this->plugin_basename(__FILE__), array(&$this, 'options_page'));
	}

	function options_page() {
		?>

		<div class="wrap">
		<form method="post" action="options.php">
			<?php wp_nonce_field('update-options') ?>
			<h2>Private URL Options</h2>
			<table class="form-table">
			<tr valign="top">
			<th scope="row">Base path for private urls</th>
			<td><input type="text" size="50" name="prvurl_path" value="<?php echo get_option('prvurl_path'); ?>" /><br/>
																  Default is <code>private</code>.  i.e. Private URLS will have the base <code><?php echo get_bloginfo('url'); ?>/private</code></td>
			</tr>
			<tr valign="top">
			<th scope="row">Default salt for private posts</th>
			<td><input type="text" size="50" name="prvurl_salt" value="<?php echo get_option('prvurl_salt'); ?>" /><br/>Treat this as a passphrase.</td>
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="page_options" value="prvurl_path,prvurl_salt" />
			</table>
			<p class="submit">
			<input type="submit" name="Submit" value="<?php _e('Save Changes') ?>" />
			</p>
			</form>
</div>


<?php
	}
}

$prvurl = new prvurl();
