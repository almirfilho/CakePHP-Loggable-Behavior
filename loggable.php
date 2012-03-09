<?php
 
App::import( 'Model', 'Log' );
App::import( 'Component', 'Session' );
 
class LoggableBehavior extends ModelBehavior {

	/*----------------------------------------
	 * Atributtes
	 ----------------------------------------*/
	
	private $Session;

	private $Log;
	
	public $settings = array();
	
	private $separator = '<br />';
	
	private $transactionStarted = false;
	
	private $exceptFields = array( 'created', 'modified' );
	
	private $fieldLabels = array( 'id' => 'ID' );
 
	/*----------------------------------------
	 * Methods
	 ----------------------------------------*/
	
	public function setup( &$Model, $config = null ){

		// $this->setModel( $model );
		$this->configure( $Model, $config );
		$this->Session	= new SessionComponent();
		$this->Log		= new Log();
	}
	
	private function configure( &$Model, $config ){

		$this->settings[ $Model->alias ] = array(
			'entidade' => $config[ 'entidade' ],
			'action' => null,
			'oldData' => null,
			'newData' => null,
			'fields' => null,
			'fieldLabels' => null,
			'exceptFields' => $this->exceptFields
		);
		
		// setando os campos (se houver)
		if( !empty( $config[ 'fields' ] ) )
			$this->settings[ $Model->alias ][ 'fields' ] = $config[ 'fields' ];
		
		// setando traducoes dos campos (se houver)
		if( !empty( $config[ 'fieldLabels' ] ) )
			$this->settings[ $Model->alias ][ 'fieldLabels' ] = array_merge( $config[ 'fieldLabels' ], $this->fieldLabels );
		
		// setando os campos execoes (se houver)
		if( !empty( $config[ 'exceptFields' ] ) )
			$this->settings[ $Model->alias ][ 'exceptFields' ] = array_merge( $config[ 'exceptFields' ], $this->exceptFields );
	}
	
	private function dataToString( &$Model, $data ){
		// debug($this->settings[ $Model->alias ][ 'exceptFields' ]);
		if( !empty( $this->settings[ $Model->alias ][ $data ] ) ){
			
			$array = array();

			foreach( $this->settings[ $Model->alias ][ $data ][ $Model->alias ] as $field => $value ){
				
				if( !in_array( $field, $this->settings[ $Model->alias ][ 'exceptFields' ] ) ){
					
					$fieldLabel = $field;
					
					if( !empty( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ] ) ){
						
						if( is_array( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ] ) ){
							
							$fieldLabel = $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'label' ];
							
							if( !empty( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ] ) ){
								
								// campo tipo date
								if( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ] == 'date' )
									$value = date( 'd/m/Y', strtotime( $value ) );
								
								// campo tipo datetime
								elseif( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ] == 'datetime' )
									$value = date( 'd/m/Y H:i:s', strtotime( $value ) );
								
								// campo tipo booleano
								elseif( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ] == 'bool' )
									$value ? $value = 'Sim' : $value = 'N&atilde;o';
								
								// campo tipo enum
								elseif( is_array( $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ] ) )
									$value = $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ][ 'type' ][ $value ];
							}
							
						} else 
							$fieldLabel = $this->settings[ $Model->alias ][ 'fieldLabels' ][ $field ];
					}
					
					if( !$value )
						$value = '--';
					
					$array[] = "<span>{$fieldLabel}</span>: {$value}";	
				}
			}
		
			return implode( $this->separator, $array );
		}
		
		return null;
	}
	
	private function write( &$Model ){
		
		if( $Model->alias == 'Log' )
			return true;

		if( $this->dataDiff( $Model ) ){
			
			$registro_id = $Model->id;
			
			if( !$registro_id )
				$this->settings[ $Model->alias ][ 'deletedId' ];
			
			// debug($this->dataToString( $Model, 'newData' ));
			// die;
			$this->Log->id = null;
			$this->Log->save( array(
				'Log' => array(
					'registro_id' => $registro_id,
					'user_id'	=> $this->Session->read( 'Auth.User.id' ),
					'entidade'	=> $this->settings[ $Model->alias ][ 'entidade' ],
					'acao'		=> $this->settings[ $Model->alias ][ 'action' ],
					'old_data'	=> $this->dataToString( $Model, 'oldData' ),
					'new_data'	=> $this->dataToString( $Model, 'newData' )
			) ), false );
		}
	}
	
	private function dataDiff( &$Model ){

		// se estiver alterando dados, verificar quais dados estao sendo alterados e ignorar o restante que permanece igual
		if( $this->settings[ $Model->alias ][ 'action' ] == 'EDIT' ){
			
			foreach( $this->settings[ $Model->alias ][ 'oldData' ][ $Model->alias ] as $field => $oldDataValue ){
				if( $this->settings[ $Model->alias ][ 'newData' ][ $Model->alias ][ $field ] == $oldDataValue || in_array( $field, $this->settings[ $Model->alias ][ 'exceptFields' ] ) ){
					
					unset( $this->settings[ $Model->alias ][ 'oldData' ][ $Model->alias ][ $field ] );
					unset( $this->settings[ $Model->alias ][ 'newData' ][ $Model->alias ][ $field ] );
				}
			}
					
			if( empty( $this->settings[ $Model->alias ][ 'oldData' ][ $Model->alias ] ) && empty( $this->settings[ $Model->alias ][ 'newData' ][ $Model->alias ] ) )
				return false;
		}
		
		return true;
	}
	
	/*----------------------------------------
	 * Model Callbacks
	 ----------------------------------------*/
	
	public function beforeSave( &$Model ){

		// $this->setModel( $model );

		if( is_numeric( $Model->id ) ){

			$this->settings[ $Model->alias ][ 'action' ] = 'EDIT';
			$params = array(
				'conditions' => array( "{$Model->alias}.id" => $Model->id ),
				'contain' => false
			);
			
			if( !empty( $this->settings[ $Model->alias ][ 'fields' ] ) )
				$params[ 'fields' ] = &$this->settings[ $Model->alias ][ 'fields' ];
			
			$this->settings[ $Model->alias ][ 'oldData' ] = $Model->find( 'first', $params );
			
		} else
			$this->settings[ $Model->alias ][ 'action' ] = 'ADD';

		$ds = $Model->getDataSource();
		
		// se nao ha transacao sendo executada, comeca transacao
		if( $ds->begin( $Model ) )
			$this->transactionStarted = true;
		
		return true;
	}
 
	public function afterSave( &$Model, $created ){

		// $this->setModel( $model );
		
		$params = array(
			'conditions' => array( "{$Model->alias}.id" => $Model->id ),
			'contain' => false
		);
		
		if( !empty( $this->settings[ $Model->alias ][ 'fields' ] ) )
			$params[ 'fields' ] = $this->settings[ $Model->alias ][ 'fields' ];
			
		$newData = $Model->find( 'first', $params );
		
		// este if/else eh necessario se estivermos usando o behavior SoftDeletable
		if( !empty( $newData[ $Model->alias ] ) )
			$this->settings[ $Model->alias ][ 'newData' ] = $newData;
		else
			$this->settings[ $Model->alias ][ 'action' ] = 'DELETE';

		$this->write( $Model );
		
		if( $this->transactionStarted ){
			
			$this->transactionStarted = false;
			$ds = $Model->getDataSource();
			return $ds->commit( $Model );
		}
		
		return true;
	}
 
	public function beforeDelete( &$Model, $cascade = true ){

		// $this->setModel( $model );
		$this->settings[ $Model->alias ][ 'action' ] = 'DELETE';
		$this->settings[ $Model->alias ][ 'deletedId' ] = $Model->id;
		
		$params = array(
			'conditions' => array( "{$Model->alias}.id" => $this->settings[ $Model->alias ][ 'deletedId' ] ),
			'contain' => false
		);
		
		if( !empty( $this->settings[ $Model->alias ][ 'fields' ] ) )
			$params[ 'fields' ] = $this->settings[ $Model->alias ][ 'fields' ];
			
		$this->settings[ $Model->alias ][ 'oldData' ] = $Model->find( 'first', $params );
 
		$ds = $Model->getDataSource();
		
		// se nao ha transacao sendo executada, comeca transacao
		if( $ds->begin( $Model ) )
			$this->transactionStarted = true;
		
		return true;
	}
 
	public function afterDelete( &$Model ){
		
		// $this->setModel( $model );
		$this->write( $Model );
		
		$ds = $Model->getDataSource();
		
		if( $this->transactionStarted ){
			
			$this->transactionStarted = false;
			$ds = $Model->getDataSource();
			return $ds->commit( $Model );
		}
		
		return true;
	}
 
}

?>