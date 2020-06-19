<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Connection Manager functionality testing.
 *
 * @package automattic/jetpack-connection
 */

namespace Automattic\Jetpack\Connection;

use Automattic\Jetpack\Connection\Test\Mock\Hooks;
use Automattic\Jetpack\Connection\Test\Mock\Options;
use Automattic\Jetpack\Constants;
use phpmock\Mock;
use phpmock\MockBuilder;
use phpmock\MockEnabledException;
use PHPUnit\Framework\TestCase;

/**
 * Connection Manager functionality testing.
 */
class ManagerTest extends TestCase {

	/**
	 * Temporary stack for `wp_redirect`.
	 *
	 * @var array
	 */
	protected $arguments_stack = array();

	const DEFAULT_TEST_CAPS = array( 'default_test_caps' );

	/**
	 * Initialize the object before running the test method.
	 */
	public function setUp() {
		$this->manager = $this->getMockBuilder( 'Automattic\Jetpack\Connection\Manager' )
			->setMethods( array( 'get_access_token' ) )
			->getMock();

		$builder = new MockBuilder();
		$builder->setNamespace( __NAMESPACE__ )
				->setName( 'apply_filters' )
				->setFunction(
					function( $filter_name, $return_value ) {
						return $return_value;
					}
				);

		$this->apply_filters = $builder->build();

		$builder = new MockBuilder();
		$builder->setNamespace( __NAMESPACE__ )
				->setName( 'wp_redirect' )
				->setFunction(
					function( $url ) {
						$this->arguments_stack['wp_redirect'] [] = array( $url );
						return true;
					}
				);

		$this->wp_redirect = $builder->build();

		// Mock the apply_filters() call in Constants::get_constant().
		$builder = new MockBuilder();
		$builder->setNamespace( 'Automattic\Jetpack' )
				->setName( 'apply_filters' )
				->setFunction(
					function( $filter_name, $value, $name ) {
						return constant( __NAMESPACE__ . "\Utils::DEFAULT_$name" );
					}
				);
		$this->constants_apply_filters = $builder->build();
	}

	/**
	 * Clean up the testing environment.
	 */
	public function tearDown() {
		unset( $this->manager );
		Constants::clear_constants();
		Mock::disableAll();
	}

	/**
	 * Test the `is_active` functionality when connected.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_active
	 */
	public function test_is_active_when_connected() {
		$access_token = (object) array(
			'secret'           => 'abcd1234',
			'external_user_id' => 1,
		);
		$this->manager->expects( $this->once() )
			->method( 'get_access_token' )
			->will( $this->returnValue( $access_token ) );

		$this->assertTrue( $this->manager->is_active() );
	}

	/**
	 * Test the `is_active` functionality when not connected.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_active
	 */
	public function test_is_active_when_not_connected() {
		$this->manager->expects( $this->once() )
			->method( 'get_access_token' )
			->will( $this->returnValue( false ) );

		$this->assertFalse( $this->manager->is_active() );
	}

	/**
	 * Test the `api_url` generation.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::api_url
	 */
	public function test_api_url_defaults() {
		$this->apply_filters->enable();
		$this->constants_apply_filters->enable();

		$this->assertEquals(
			'https://jetpack.wordpress.com/jetpack.something/1/',
			$this->manager->api_url( 'something' )
		);
		$this->assertEquals(
			'https://jetpack.wordpress.com/jetpack.another_thing/1/',
			$this->manager->api_url( 'another_thing/' )
		);
	}

	/**
	 * Testing the ability of the api_url method to follow set constants and filters.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::api_url
	 */
	public function test_api_url_uses_constants_and_filters() {
		$this->apply_filters->enable();
		$this->constants_apply_filters->enable();

		Constants::set_constant( 'JETPACK__API_BASE', 'https://example.com/api/base.' );
		$this->assertEquals(
			'https://example.com/api/base.something/1/',
			$this->manager->api_url( 'something' )
		);

		Constants::set_constant( 'JETPACK__API_BASE', 'https://example.com/api/another.' );
		Constants::set_constant( 'JETPACK__API_VERSION', '99' );
		$this->assertEquals(
			'https://example.com/api/another.something/99/',
			$this->manager->api_url( 'something' )
		);

		$this->apply_filters->disable();

		// Getting a new special mock just for this occasion.
		$builder = new MockBuilder();
		$builder->setNamespace( __NAMESPACE__ )
				->setName( 'apply_filters' )
				->setFunction(
					// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
					function( $filter_name, $return_value ) {
						$this->arguments_stack[ $filter_name ] [] = func_get_args();
						return 'completely overwrite';
					}
				);

		$builder->build()->enable();

		$this->assertEquals(
			'completely overwrite',
			$this->manager->api_url( 'something' )
		);

		// The jetpack_api_url argument stack should not be empty, making sure the filter was
		// called with a proper name and arguments.
		$call_arguments = array_pop( $this->arguments_stack['jetpack_api_url'] );
		$this->assertEquals( 'something', $call_arguments[2] );
		$this->assertEquals(
			Constants::get_constant( 'JETPACK__API_BASE' ),
			$call_arguments[3]
		);
		$this->assertEquals(
			'/' . Constants::get_constant( 'JETPACK__API_VERSION' ) . '/',
			$call_arguments[4]
		);
	}

	/**
	 * Test the `is_user_connected` functionality.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_user_connected
	 */
	public function test_is_user_connected_with_default_user_id_logged_out() {
		$this->mock_function( 'get_current_user_id', 0 );

		$this->assertFalse( $this->manager->is_user_connected() );
	}

	/**
	 * Test the `is_user_connected` functionality.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_user_connected
	 */
	public function test_is_user_connected_with_false_user_id_logged_out() {
		$this->mock_function( 'get_current_user_id', 0 );

		$this->assertFalse( $this->manager->is_user_connected( false ) );
	}

	/**
	 * Test the `is_user_connected` functionality
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_user_connected
	 */
	public function test_is_user_connected_with_user_id_logged_out_not_connected() {
		$this->mock_function( 'absint', 1 );
		$this->manager->expects( $this->once() )
			->method( 'get_access_token' )
			->will( $this->returnValue( false ) );

		$this->assertFalse( $this->manager->is_user_connected( 1 ) );
	}


	/**
	 * Test the `is_user_connected` functionality.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_user_connected
	 */
	public function test_is_user_connected_with_default_user_id_logged_in() {
		$this->mock_function( 'get_current_user_id', 1 );
		$access_token = (object) array(
			'secret'           => 'abcd1234',
			'external_user_id' => 1,
		);
		$this->manager->expects( $this->once() )
			->method( 'get_access_token' )
			->will( $this->returnValue( $access_token ) );

		$this->assertTrue( $this->manager->is_user_connected() );
	}

	/**
	 * Test the `is_user_connected` functionality.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::is_user_connected
	 */
	public function test_is_user_connected_with_user_id_logged_in() {
		$this->mock_function( 'absint', 1 );
		$access_token = (object) array(
			'secret'           => 'abcd1234',
			'external_user_id' => 1,
		);
		$this->manager->expects( $this->once() )
			->method( 'get_access_token' )
			->will( $this->returnValue( $access_token ) );

		$this->assertTrue( $this->manager->is_user_connected( 1 ) );
	}

	/**
	 * Test the `jetpack_connection_custom_caps' method.
	 *
	 * @covers Automattic\Jetpack\Connection\Manager::jetpack_connection_custom_caps
	 * @dataProvider jetpack_connection_custom_caps_data_provider
	 *
	 * @param bool   $in_dev_mode Whether development mode is active.
	 * @param string $custom_cap The custom capability that is being tested.
	 * @param array  $expected_caps The expected output.
	 */
	public function test_jetpack_connection_custom_caps( $in_dev_mode, $custom_cap, $expected_caps ) {
		// Mock the site_url call in Status::is_development_mode.
		$this->mock_function( 'site_url', false, 'Automattic\Jetpack' );

		// Mock the apply_filters( 'jetpack_development_mode', ) call in Status::is_development_mode.
		$this->mock_function( 'apply_filters', $in_dev_mode, 'Automattic\Jetpack' );

		// Mock the apply_filters( 'jetpack_disconnect_cap', ) call in jetpack_connection_custom_caps.
		$this->mock_function( 'apply_filters', array( 'manage_options' ) );

		$caps = $this->manager->jetpack_connection_custom_caps( self::DEFAULT_TEST_CAPS, $custom_cap, 1, array() );
		$this->assertEquals( $expected_caps, $caps );
	}

	/**
	 * Data provider test_jetpack_connection_custom_caps.
	 *
	 * The test data arrays contain:
	 *    'in dev mode': Whether development mode is active.
	 *    'custom cap': The custom capbability that is being tested.
	 *    'expected caps': The expected output of the call to jetpack_connection_custom_caps. An array of strings.
	 */
	public function jetpack_connection_custom_caps_data_provider() {
		return array(
			'dev mode, jetpack_connect'          => array( true, 'jetpack_connect', array( 'do_not_allow' ) ),
			'dev mode, jetpack_reconnect'        => array( true, 'jetpack_reconnect', array( 'do_not_allow' ) ),
			'dev mode, jetpack_disconnect'       => array( true, 'jetpack_disconnect', array( 'manage_options' ) ),
			'dev mode, jetpack_connect_user'     => array( true, 'jetpack_connect_user', array( 'do_not_allow' ) ),
			'dev mode, unknown cap'              => array( true, 'unknown_cap', self::DEFAULT_TEST_CAPS ),
			'not dev mode, jetpack_connect'      => array( false, 'jetpack_connect', array( 'manage_options' ) ),
			'not dev mode, jetpack_reconnect'    => array( false, 'jetpack_reconnect', array( 'manage_options' ) ),
			'not dev mode, jetpack_disconnect'   => array( false, 'jetpack_disconnect', array( 'manage_options' ) ),
			'not dev mode, jetpack_connect_user' => array( false, 'jetpack_connect_user', array( 'read' ) ),
			'not dev mode, unknown cap'          => array( false, 'unknown_cap', self::DEFAULT_TEST_CAPS ),
		);
	}

	/**
	 * Mock a global function and make it return a certain value.
	 *
	 * @param string $function_name Name of the function.
	 * @param mixed  $return_value Return value of the function.
	 * @param string $namespace The namespace of the function.
	 *
	 * @return Mock The mock object.
	 * @throws MockEnabledException PHPUnit wasn't able to enable mock functions.
	 */
	protected function mock_function( $function_name, $return_value = null, $namespace = __NAMESPACE__ ) {
		$builder = new MockBuilder();
		$builder->setNamespace( $namespace )
			->setName( $function_name )
			->setFunction(
				function() use ( &$return_value ) {
					return $return_value;
				}
			);

		$mock = $builder->build();
		$mock->enable();

		return $mock;
	}

}
