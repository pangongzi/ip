<?php



namespace Pangongzi\Ip;

class IpXdbSearcher
{
  public const HeaderInfoLength = 256;
  public const VectorIndexRows = 256;
  public const VectorIndexCols = 256;
  public const VectorIndexSize = 8;
  public const SegmentIndexSize = 14;

  // xdb file handle
  private $handle = null;

  // header info
  private $header = null;

  private $ioCount = 0;

  // vector index in binary string.
  // string decode will be faster than the map based Array.
  private $vectorIndex = null;

  // xdb content buffer
  private $contentBuff = null;


  // 单例实例
  private static $instance = null;


  // 默认数据
  private const DATA_FILE = __DIR__ . '/data/ip2region.xdb';

  // ---
  // static function to create searcher

  /**
   * 完全基于文件的查询
   * @throws \Exception
   */
  public static function newWithFileOnly($dbFile = null)
  {
    return new self($dbFile, null, null);
  }

  /**
   * 缓存 VectorIndex 索引
   * @throws \Exception
   */
  public static function newWithVectorIndex($dbFile = null, $vIndex = null)
  {
    return new self($dbFile, $vIndex);
  }

  /**
   * @throws \Exception
   */
  public static function newWithBuffer($cBuff)
  {
    return new self(null, null, $cBuff);
  }

  // --- End of static creator


  // 获取单例实例的方法
  public static function getInstance($dbFile = null, $vectorIndex = null, $cBuff = null)
  {
    if (self::$instance === null) {
      self::$instance = new self($dbFile, $vectorIndex, $cBuff);
    }
    return self::$instance;
  }


  /**
   * initialize the xdb searcher
   * @throws \Exception
   */
  public function __construct($dbFile = null, $vectorIndex = null, $cBuff = null)
  {
    // check the content buffer first
    if ($cBuff != null) {
      $this->vectorIndex = null;
      $this->contentBuff = $cBuff;
    } else {

      // 使用默认数据
      if ($dbFile === null) {
        $dbFile = self::DATA_FILE;
      }

      // open the xdb binary file
      $this->handle = fopen($dbFile, "r");
      if ($this->handle === false) {
        throw new \Exception("failed to open xdb file '%s'", $dbFile);
      }

      $this->vectorIndex = $vectorIndex;
    }
  }

  public function close()
  {
    if ($this->handle != null) {
      fclose($this->handle);
    }
  }

  public function getIOCount()
  {
    return $this->ioCount;
  }

  /**
   * 中国|0|浙江省|绍兴市|电信
   * find the region info for the specified ip address
   * @throws \Exception
   */
  public function search($ip)
  {
    // check and convert the sting ip to a 4-bytes long
    if (is_string($ip)) {
      $t = self::ip2long($ip);
      if ($t === null) {
        throw new \Exception("invalid ip address `$ip`");
      }
      $ip = $t;
    }

    // reset the global counter
    $this->ioCount = 0;

    // locate the segment index block based on the vector index
    $il0 = ($ip >> 24) & 0xFF;
    $il1 = ($ip >> 16) & 0xFF;
    $idx = $il0 * self::VectorIndexCols * self::VectorIndexSize + $il1 * self::VectorIndexSize;
    if ($this->vectorIndex != null) {
      $sPtr = self::getLong($this->vectorIndex, $idx);
      $ePtr = self::getLong($this->vectorIndex, $idx + 4);
    } elseif ($this->contentBuff != null) {
      $sPtr = self::getLong($this->contentBuff, self::HeaderInfoLength + $idx);
      $ePtr = self::getLong($this->contentBuff, self::HeaderInfoLength + $idx + 4);
    } else {
      // read the vector index block
      $buff = $this->read(self::HeaderInfoLength + $idx, 8);
      if ($buff === null) {
        throw new \Exception("failed to read vector index at {$idx}");
      }

      $sPtr = self::getLong($buff, 0);
      $ePtr = self::getLong($buff, 4);
    }

    // printf("sPtr: %d, ePtr: %d\n", $sPtr, $ePtr);

    // binary search the segment index to get the region info
    $dataLen = 0;
    $dataPtr = null;
    $l = 0;
    $h = ($ePtr - $sPtr) / self::SegmentIndexSize;
    while ($l <= $h) {
      $m = ($l + $h) >> 1;
      $p = $sPtr + $m * self::SegmentIndexSize;

      // read the segment index
      $buff = $this->read($p, self::SegmentIndexSize);
      if ($buff == null) {
        throw new \Exception("failed to read segment index at {$p}");
      }

      $sip = self::getLong($buff, 0);
      if ($ip < $sip) {
        $h = $m - 1;
      } else {
        $eip = self::getLong($buff, 4);
        if ($ip > $eip) {
          $l = $m + 1;
        } else {
          $dataLen = self::getShort($buff, 8);
          $dataPtr = self::getLong($buff, 10);
          break;
        }
      }
    }

    // match nothing interception.
    // @TODO: could this even be a case ?
    // printf("dataLen: %d, dataPtr: %d\n", $dataLen, $dataPtr);
    if ($dataPtr == null) {
      return null;
    }

    // load and return the region data
    $buff = $this->read($dataPtr, $dataLen);
    if ($buff == null) {
      return null;
    }

    return $buff;
  }

  // read specified bytes from the specified index
  private function read($offset, $len)
  {
    // check the in-memory buffer first
    if ($this->contentBuff != null) {
      return substr($this->contentBuff, $offset, $len);
    }

    // read from the file
    $r = fseek($this->handle, $offset);
    if ($r == -1) {
      return null;
    }

    $this->ioCount++;
    $buff = fread($this->handle, $len);
    if ($buff === false) {
      return null;
    }

    if (strlen($buff) != $len) {
      return null;
    }

    return $buff;
  }


  /**
   * 格式化 ip 数据 中国|0|浙江省|绍兴市|电信
   * @param string $ipData
   * @return array {city: string, country: string, isp: string, province: string, region: string, type: string}
   */
  public function format($ipData)
  {
    $itemArr = explode('|', $ipData);
    // 返回目标数组结构
    return [
      'country' => $itemArr[0] ?? '',       // 国家 中国
      'region' => $itemArr[1] ?? '',        // 大区 0
      'province' => $itemArr[2] ?? '',      // 省份 浙江省
      'city' => $itemArr[3] ?? '',          // 城市 绍兴市
      'isp' => $itemArr[4] ?? ''            // 运营商
    ];
  }



  // --- static util functions ----

  // convert a string ip to long
  public static function ip2long($ip)
  {
    $ip = ip2long($ip);
    if ($ip === false) {
      return null;
    }

    // convert signed int to unsigned int if on 32 bit operating system
    if ($ip < 0 && PHP_INT_SIZE == 4) {
      $ip = sprintf("%u", $ip);
    }

    return $ip;
  }

  // read a 4bytes long from a byte buffer
  public static function getLong($b, $idx)
  {
    $val = (ord($b[$idx])) | (ord($b[$idx + 1]) << 8)
      | (ord($b[$idx + 2]) << 16) | (ord($b[$idx + 3]) << 24);

    // convert signed int to unsigned int if on 32 bit operating system
    if ($val < 0 && PHP_INT_SIZE == 4) {
      $val = sprintf("%u", $val);
    }

    return $val;
  }

  // read a 2bytes short from a byte buffer
  public static function getShort($b, $idx)
  {
    return ((ord($b[$idx])) | (ord($b[$idx + 1]) << 8));
  }

  // load header info from a specified file handle
  public static function loadHeader($handle)
  {
    if (fseek($handle, 0) == -1) {
      return null;
    }

    $buff = fread($handle, self::HeaderInfoLength);
    if ($buff === false) {
      return null;
    }

    // read bytes length checking
    if (strlen($buff) != self::HeaderInfoLength) {
      return null;
    }

    // return the decoded header info
    return [
      'version'       => self::getShort($buff, 0),
      'indexPolicy'   => self::getShort($buff, 2),
      'createdAt'     => self::getLong($buff, 4),
      'startIndexPtr' => self::getLong($buff, 8),
      'endIndexPtr'   => self::getLong($buff, 12)
    ];
  }

  // load header info from the specified xdb file path
  public static function loadHeaderFromFile($dbFile)
  {
    $handle = fopen($dbFile, 'r');
    if ($handle === false) {
      return null;
    }

    $header = self::loadHeader($handle);
    fclose($handle);
    return $header;
  }

  // load vector index from a file handle
  public static function loadVectorIndex($handle)
  {
    if (fseek($handle, self::HeaderInfoLength) == -1) {
      return null;
    }

    $rLen = self::VectorIndexRows * self::VectorIndexCols * self::SegmentIndexSize;
    $buff = fread($handle, $rLen);
    if ($buff === false) {
      return null;
    }

    if (strlen($buff) != $rLen) {
      return null;
    }

    return $buff;
  }

  // load vector index from a specified xdb file path
  public static function loadVectorIndexFromFile($dbFile)
  {
    $handle = fopen($dbFile, 'r');
    if ($handle === false) {
      return null;
    }

    return self::loadVectorIndex($handle);
  }

  // load the xdb content from a file handle
  public static function loadContent($handle)
  {
    if (fseek($handle, 0, SEEK_END) == -1) {
      return null;
    }

    $size = ftell($handle);
    if ($size === false) {
      return null;
    }

    // seek to the head for reading
    if (fseek($handle, 0) == -1) {
      return null;
    }

    $buff = fread($handle, $size);
    if ($buff === false) {
      return null;
    }

    // read length checking
    if (strlen($buff) != $size) {
      return null;
    }

    return $buff;
  }


  // load the xdb content from a file path
  /**
   * 缓存整个 xdb 数据
   * 如果你的 PHP 母环境支持，可以预先加载整个 xdb 的数据到内存，
   * 这样可以实现完全基于内存的查询，类似之前的 memory search 查询。
   */
  public static function loadContentFromFile($dbFile)
  {
    $str = file_get_contents($dbFile, false);
    if ($str === false) {
      return null;
    } else {
      return $str;
    }
  }

  public static function now()
  {
    return (microtime(true) * 1000);
  }
}
