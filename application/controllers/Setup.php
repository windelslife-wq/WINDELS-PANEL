<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** One-time, operator-authorized first administrator setup. */
class Setup extends MY_Controller {
    public function __construct() {
        parent::__construct();
        if (!$this->db_ready || !$this->auth) {
            show_error('Setup is temporarily unavailable. Check the database configuration.', 503);
        }
        $this->load->library(array('form_validation', 'RateLimiter'));
    }

    public function index() {
        $this->render_setup();
    }

    public function admin() {
        if ($this->input->method(true) !== 'POST') return redirect('setup');
        if (ENVIRONMENT === 'production' && !Env::request_is_https()) {
            show_error('First administrator setup requires HTTPS.', 403);
        }
        $ip = $this->input->ip_address();
        $bucket = RateLimiter::scope('setup', 'first-admin');
        if ($this->ratelimiter->too_many_failures($ip, $bucket, 5, 900)) {
            return $this->render_setup('Too many setup attempts. Please try again later.');
        }
        if (!$this->auth->bootstrap_admin_open()) {
            return $this->render_setup('Setup is closed because a staff account already exists. Sign in at /admin/login or use password recovery.');
        }
        $this->form_validation->set_rules('username', 'Username', 'required|trim|alpha_numeric|min_length[3]|max_length[64]');
        $this->form_validation->set_rules('email', 'Email', 'required|trim|valid_email|max_length[255]');
        $this->form_validation->set_rules('password', 'Password', 'required|min_length[12]|max_length[255]');
        $this->form_validation->set_rules('password_confirm', 'Confirm password', 'required|matches[password]');
        $this->form_validation->set_rules('setup_token', 'Setup token', 'required');
        if (!$this->form_validation->run()) {
            $this->ratelimiter->record($bucket, $ip, false, 'SETUP_INVALID', $this->input->user_agent());
            return $this->render_setup();
        }
        $result = $this->auth->register_first_admin(array(
            'username' => $this->input->post('username', true),
            'email' => $this->input->post('email', true),
            'password' => $this->input->post('password'),
            'ip' => $ip,
        ), $this->input->post('setup_token'));
        if (empty($result['ok'])) {
            $this->ratelimiter->record($bucket, $ip, false, 'SETUP_FAILED', $this->input->user_agent());
            $messages = array(
                'BOOTSTRAP_DENIED' => 'Setup token is invalid or not configured.',
                'BOOTSTRAP_CLOSED' => 'Setup is closed because a staff account already exists. Sign in at /admin/login or use password recovery.',
                'REGISTRATION_BUSY' => 'Setup is busy. Please try again.',
                'EMAIL_TAKEN' => 'An account with that email already exists.',
                'USERNAME_TAKEN' => 'That username is already taken.',
            );
            return $this->render_setup($messages[$result['error']] ?? 'Setup could not be completed. Please try again.');
        }
        $this->ratelimiter->record($bucket, $ip, true, 'SETUP_OK', $this->input->user_agent());
        $this->session->set_flashdata('success', 'First administrator created. Sign in with your new credentials. Remove VP_SETUP_TOKEN from the server configuration.');
        return redirect('admin/login');
    }

    private function render_setup($error = null) {
        $this->output->set_header('Cache-Control: no-store');
        $this->load->view('layouts/auth', array(
            'content_view' => 'auth/first_admin', 'title' => 'First administrator setup',
            'current_user' => $this->auth->user(),
            'bootstrap_open' => $this->auth->bootstrap_admin_open(), 'setup_error' => $error,
        ));
    }
}
