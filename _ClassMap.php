<?php

# _ClassMap.php file exists for autoloader, it allows to provide fast class to files mapping to quickly stop searching for specific classes
# This file may only be present in root class paths directly added to autoloader, and loaded only on addPath(), path subdirectories are NOT scanned for such files

# Why class map? Why not just split each small supporting class into its own file like our friend frameworks do?
# The file and PHP preprocessing operations ARE NOT CHEAP. Especially on clustered and remote file systems
# Our dear frameworks take ages to load and initialize their first requests exactly due to bloody lot of files being loaded and preprocessed to opcache
# Also, if you do not have opcache with lots of files, you are doomed. As AT/Library is a runtime library and NOT a framework, it cannot afford to be THAT heavy
# Additionally, placing supporting classes that are meaningless into the file with the primary class makes it easier to search for stuff semantically

# _ClassMap.php returns but one thing, an array of class names (namespace included) with the file names relative to the directory _ClassMap.php is in
# As _ClassMap.php is a PHP processed file, it is perfectly opcache cacheable, so it does not incur much overhead for itself

# The class map here is very explicit, containing every single class, trait and interface AT/Library has published
# There is technically no need for it, but it also acts as a quick glance class list and speeds up class loading by eliminating searches

return
[
    ########
    # Task and TaskLoop (fast cooperative multitasking [coroutine] engine)

    # Main task loop (TaskLoop)
    'ATL\\ITaskLoop' => 'Task/TaskLoop.php',
    'ATL\\TTaskLoop' => 'Task/TaskLoop.php',
    'ATL\\TaskLoop' => 'Task/TaskLoop.php',
    'ATL\\TaskLoopException' => 'Task/TaskLoop.php',

    # Main task object (Task)
    'ATL\\ITask' => 'Task/Task.php',
    'ATL\\TTask' => 'Task/Task.php',
    'ATL\\Task' => 'Task/Task.php',
    'ATL\\SimpleTask' => 'Task/Task.php',
    'ATL\\TaskException' => 'Task/Task.php',

    # Supporting primitives
    'ATL\\Task\\IWaitOn' => 'Task/Primitives.php',
    'ATL\\Task\\TWaitOn' => 'Task/Primitives.php',
    'ATL\\Task\\WaitOn' => 'Task/Primitives.php',
    'ATL\\Task\\WaitOnAny' => 'Task/Primitives.php',
    'ATL\\Task\\Promise' => 'Task/Primitives.php',
    'ATL\\Task\\GarbageCollector' => 'Task/Primitives.php',

    ########
    # Objects

    # Bindable objects
    'ATL\\IBindableObject' => 'Objects/BindableObject.php',
    'ATL\\TBindableObject' => 'Objects/BindableObject.php',
    'ATL\\BindableObject' => 'Objects/BindableObject.php',
    'ATL\\BindableObjectBindingException' => 'Objects/BindableObject.php',
    'ATL\\BindableObjectUnbindingException' => 'Objects/BindableObject.php',
    'ATL\\BindableObjectObjectNotFoundException' => 'Objects/BindableObject.php',

    ########
    # Structures / Containers

    # Permissions tree
    'ATL\\IPermissionsTree' => 'Structures/PermissionsTree.php',
    'ATL\\TPermissionsTree' => 'Structures/PermissionsTree.php',
    'ATL\\PermissionsTree' => 'Structures/PermissionsTree.php',

    ########
    # Containers

    # Object Registry
    'ATL\\IObjectRegistry' => 'Containers/ObjectRegistry.php',
    'ATL\\TObjectRegistry' => 'Containers/ObjectRegistry.php',
    'ATL\\ObjectRegistry' => 'Containers/ObjectRegistry.php',

    # General object container
    'ATL\\IObjectContainer' => 'Containers/ObjectContainer.php',
    'ATL\\TObjectContainer' => 'Containers/ObjectContainer.php',
    'ATL\\ObjectContainer' => 'Containers/ObjectContainer.php',
    'ATL\\ObjectAutoIndex' => 'Containers/ObjectContainer.php',
    'ATL\\ObjectContainerStoreException' => 'Containers/ObjectContainer.php',
    'ATL\\ObjectContainerRemoveException' => 'Containers/ObjectContainer.php',
    'ATL\\ObjectContainerNotFoundException' => 'Containers/ObjectContainer.php',

    ########
    # Object Store (model abstraction)

    # Base ObjectStore class
    'ATL\\IObjectStore' => 'ObjectStore/ObjectStore.php',
    'ATL\\TObjectStore' => 'ObjectStore/ObjectStore.php',
    'ATL\\ObjectStore' => 'ObjectStore/ObjectStore.php',
    'ATL\\ObjectStoreException' => 'ObjectStore.php',
    'ATL\\ObjectStoreDuplicateException' => 'ObjectStore.php',
    'ATL\\ObjectStoreItemException' => 'ObjectStore.php',

    # Base Item class
    'ATL\\ObjectStore\\IItem' => 'ObjectStore/Item.php',
    'ATL\\ObjectStore\\TItem' => 'ObjectStore/Item.php',
    'ATL\\ObjectStore\\Item' => 'ObjectStore/Item.php',

    # RDBMS driver based ObjectStore
    'ATL\\ObjectStore\\IRDBMS' => 'ObjectStore/RDBMS.php',
    'ATL\\ObjectStore\\TRDBMS' => 'ObjectStore/RDBMS.php',
    'ATL\\ObjectStore\\RDBMS' => 'ObjectStore/RDBMS.php',

    # Sample BindableObject Item extension
    'ATL\\ObjectStore\\Item\\IBindableObject' => 'ObjectStore/Item/BindableObject.php',
    'ATL\\ObjectStore\\Item\\TBindableObject' => 'ObjectStore/Item/BindableObject.php',
    'ATL\\ObjectStore\\Item\\BindableObject' => 'ObjectStore/Item/BindableObject.php',

    ########
    # RDBMS (database and database query abstraction layer)

    # Base RDBMS driver class
    'ATL\\RDMBS\\IDriver' => 'RDBMS/Driver.php',
    'ATL\\RDMBS\\TDriver' => 'RDBMS/Driver.php',
    'ATL\\RDMBS\\Driver' => 'RDBMS/Driver.php',
    'ATL\\RDBMS\\DriverException' => 'RDBMS/Driver.php',

    # MySQLi driver
    'ATL\\RDBMS\\IMySQLi' => 'RDBMS/MySQLi.php',
    'ATL\\RDBMS\\TMySQLi' => 'RDBMS/MySQLi.php',
    'ATL\\RDBMS\\MySQLi' => 'RDBMS/MySQLi.php',

    # SQLite3 driver
    'ATL\\RDBMS\\ISQLite3' => 'RDBMS/SQLite3.php',
    'ATL\\RDBMS\\TSQLite3' => 'RDBMS/SQLite3.php',
    'ATL\\RDBMS\\SQLite3' => 'RDBMS/SQLite3.php',

    ########
    # Sockets

    ########
    # Socket abstraction layer

    # Base socket class
    'ATL\\ISocket' => 'Socket/Abstraction/Socket.php',
    'ATL\\TSocket' => 'Socket/Abstraction/Socket.php',
    'ATL\\Socket' => 'Socket/Abstraction/Socket.php',
    'ATL\\SocketException' => 'Socket/Abstraction/Socket.php',

    # Additional socket capabilities
    'ATL\\Socket\\Capabilities\\IBulk' => 'Socket/Abstraction/Capabilities.php',
    'ATL\\Socket\\Capabilities\\TBulk' => 'Socket/Abstraction/Capabilities.php',
    'ATL\\Socket\\Capabilities\\IReadBytes' => 'Socket/Abstraction/Capabilities.php',
    'ATL\\Socket\\Capabilities\\TReadBytes' => 'Socket/Abstraction/Capabilities.php',
    'ATL\\Socket\\Capabilities\\IDelimitedReads' => 'Socket/Abstraction/Capabilities.php',
    'ATL\\Socket\\Capabilities\\TDelimitedReads' => 'Socket/Abstraction/Capabilities.php',

    # Polling socket abstraction class
    'ATL\\Socket\\IPollingSocket' => 'Socket/Abstraction/PollingSocket.php',
    'ATL\\Socket\\TPollingSocket' => 'Socket/Abstraction/PollingSocket.php',
    'ATL\\Socket\\PollingSocket' => 'Socket/Abstraction/PollingSocket.php',

    # Socket polling factory
    'ATL\\Socket\\IPollingFactory' => 'Socket/Abstraction/PollingSocket.php',
    'ATL\\Socket\\TPollingFactory' => 'Socket/Abstraction/PollingSocket.php',
    'ATL\\Socket\\PollingFactory' => 'Socket/Abstraction/PollingSocket.php',

    ########
    # Base socket type classes

    # PHP stream sockets and polling factory
    'ATL\\Socket\\StreamBase' => 'Socket/Base/Stream.php',
    'ATL\\Socket\\Stream' => 'Socket/Base/Stream.php',
    'ATL\\Socket\\DatagramStream' => 'Socket/Base/Stream.php',
    'ATL\\Socket\\IStreamPollingFactory' => 'Socket/Base/Stream.php',
    'ATL\\Socket\\TStreamPollingFactory' => 'Socket/Base/Stream.php',
    'ATL\\Socket\\StreamPollingFactory' => 'Socket/Base/Stream.php',

    # PHP socket client stream sockets
    'ATL\\Socket\\ISocketClientStream' => 'Socket/Base/SocketClientStream.php',
    'ATL\\Socket\\TSocketClientStream' => 'Socket/Base/SocketClientStream.php',
    'ATL\\Socket\\SocketClientStream' => 'Socket/Base/SocketClientStream.php',
    'ATL\\Socket\\SocketClientDatagramStream' => 'Socket/Base/SocketClientStream.php',

    # Virtual socket endpoints
    'ATL\\Socket\\IEndpointBase' => 'Socket/Base/Endpoint.php',
    'ATL\\Socket\\EndpointBase' => 'Socket/Base/Endpoint.php',
    'ATL\\Socket\\Endpoint' => 'Socket/Base/Endpoint.php',
    'ATL\\Socket\\DatagramEndpoint' => 'Socket/Base/Endpoint.php',

    ########
    # Outgoing (connector) sockets

    # Base network sockets
    'ATL\\Socket\\TCP' => 'Socket/TCP.php',
    'ATL\\Socket\\UDP' => 'Socket/UDP.php',
    'ATL\\Socket\\ICMP' => 'Socket/ICMP.php',

    # Unix sockets support
    'ATL\\Socket\\Unix' => 'Socket/Unix.php',

    ## Incoming socket acceptors and their incoming sockets/endpoints

    # TCP
    'ATL\\Socket\\TCPIncoming' => 'Socket/TCPAcceptor.php',
    'ATL\\Socket\\ITCPAcceptor' => 'Socket/TCPAcceptor.php',
    'ATL\\Socket\\TTCPAcceptor' => 'Socket/TCPAcceptor.php',
    'ATL\\Socket\\TCPAcceptor' => 'Socket/TCPAcceptor.php',

    ########
    # Sockets supporting code

    # Supporting socket primitives
    'ATL\\Socket\\SocketPrimitive' => 'Socket/Primitives.php',
    'ATL\\Socket\\WaitForConnect' => 'Socket/Primitives.php',
    'ATL\\Socket\\Disconnect' => 'Socket/Primitives.php',
    'ATL\\Socket\\Read' => 'Socket/Primitives.php',
    'ATL\\Socket\\ReadBytes' => 'Socket/Primitives.php',
    'ATL\\Socket\\ReadDelimited' => 'Socket/Primitives.php',
    'ATL\\Socket\\WaitForWriteFlush' => 'Socket/Primitives.php',

    ########
    # Locking manager

    # Basic file-based locking support routines
    'ATL\\IFileLocking' => 'Locking/FileLocking.php',
    'ATL\\TFileLocking' => 'Locking/FileLocking.php',
    'ATL\\FileLocking' => 'Locking/FileLocking.php',
    'ATL\\LockingException' => 'Locking/FileLocking.php',

    # Simple file-based locking
    'ATL\\IFileLocking\\ISimple' => 'Locking/Simple.php',
    'ATL\\IFileLocking\\TSimple' => 'Locking/Simple.php',
    'ATL\\IFileLocking\\Simple' => 'Locking/Simple.php',

    # Read-Write file-based locking with lock upgrade/downgrade capability
    'ATL\\IFileLocking\\IRW' => 'Locking/RW.php',
    'ATL\\IFileLocking\\TRW' => 'Locking/RW.php',
    'ATL\\IFileLocking\\RW' => 'Locking/RW.php',

    ########
    # Transactions

    # Transaction manager
    'ATL\\ITransactionManager' => 'Transactional/TransactionManager.php',
    'ATL\\TTransactionManager' => 'Transactional/TransactionManager.php',
    'ATL\\TransactionManager' => 'Transactional/TransactionManager.php',
    'ATL\\TransactionManagerException' => 'Transactional/TransactionManager.php',

    # Base transactional entity object
    'ATL\\Transaction\\TransactionException' => 'Transactional/Entity.php',

    # Transactional extension for MySQLi
    'ATL\\Transaction\\MySQLiException' => 'Transactional/MySQLi.php',

    # Transactional extension for SQLite3
    'ATL\\Transaction\\SQLite3Exception' => 'Transactional/SQLite3.php',

    ########
    # Formats

    # ATL quick batch data file format
    'ATL\\IBatchDataFile' => 'Formats/BatchDataFile.php',
    'ATL\\TBatchDataFile' => 'Formats/BatchDataFile.php',
    'ATL\\BatchDataFile' => 'Formats/BatchDataFile.php',

    # BASE32 encoding
    'ATL\\BASE32' => 'Formats/BASE32.php',

    # BATL85 encoding
    'ATL\\IBATL85' => 'Formats/BATL85.php',
    'ATL\\TBATL85' => 'Formats/BATL85.php',
    'ATL\\BATL85' => 'Formats/BATL85.php',
    'ATL\\BATL85EncodeTask' => 'Formats/BATL85.php',
    'ATL\\BATL85DecodeTask' => 'Formats/BATL85.php',

    ########
    # Routines

    # Messaging pattern
    'ATL\\IMessaging' => 'Routines/Messaging.php',
    'ATL\\TMessaging' => 'Routines/Messaging.php',
    'ATL\\Messaging' => 'Routines/Messaging.php',
    'ATL\\MessagingCallback' => 'Routines/Messaging.php',
    'ATL\\Messaging' => 'Routines/Messaging.php',
    'ATL\\MessagingException' => 'Routines/Messaging.php',

    # CURL
    'ATL\\ICURL' => 'Routines/CURL.php',
    'ATL\\TCURL' => 'Routines/CURL.php',
    'ATL\\CURL' => 'Routines/CURL.php',
    'ATL\\MultiCURL' => 'Routines/CURL.php',

    # Event Handler support
    'ATL\\IEventHandlers' => 'Routines/EventHandlers.php',
    'ATL\\TEventHandlers' => 'Routines/EventHandlers.php',

    # IP prefix handling
    'ATL\\IPPrefix' => 'Routines/IPPrefix.php',
    'ATL\\IPv4Prefix' => 'Routines/IPPrefix.php',
    'ATL\\IPv6Prefix' => 'Routines/IPPrefix.php',
    
    # Long floating point numbers
    'ATL\\LongFloat' => 'Routines/LongFloat.php',

    # Miscellaneous routines and helper objects
    'ATL\\Routines' => 'Routines/Routines.php',
    'ATL\\StreamBase64Data' => 'Routines/Helpers.php',
    'ATL\\ValueObject' => 'Routines/Helpers.php',

    ########
    # Protocols

    # Asterisk AMI protocol implementation
    'ATL\\Protocols\\Asterisk\\AMI' => 'Asterisk/AMI.php',

    # Asterisk AMI protocol supporting primitives
    'ATL\\Protocols\\Asterisk\\AMI\\Message' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\Primitive' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\WaitForConnect' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\Disconnect' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\Action' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\Command' => 'Protocols/Asterisk/AMI/Primitives.php',
    'ATL\\Protocols\\Asterisk\\AMI\\Authenticate' => 'Protocols/Asterisk/AMI/Primitives.php',
];
