<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getProjectId()
 * @method void setProjectId(int $v)
 * @method string getVendor()
 * @method void setVendor(string $v)
 * @method string|null getInvoiceNo()
 * @method void setInvoiceNo(string|null $v)
 * @method \DateTime getInvoiceDate()
 * @method void setInvoiceDate(\DateTime $v)
 * @method \DateTime|null getPaymentDate()
 * @method void setPaymentDate(\DateTime|null $v)
 * @method string getCurrency()
 * @method void setCurrency(string $v)
 * @method int|null getFxRateMilli()
 * @method void setFxRateMilli(int|null $v)
 * @method int getTotalMinor()
 * @method void setTotalMinor(int $v)
 * @method int getTaxMinor()
 * @method void setTaxMinor(int $v)
 * @method string|null getPurpose()
 * @method void setPurpose(string|null $v)
 * @method int|null getFileId()
 * @method void setFileId(int|null $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method bool getDupFlag()
 * @method void setDupFlag(bool $v)
 * @method string|null getExternalRef()
 * @method void setExternalRef(string|null $v)
 * @method string|null getSalaryPeriod()
 * @method void setSalaryPeriod(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class Receipt extends Entity
{
	protected int $projectId = 0;
	protected string $vendor = '';
	protected ?string $invoiceNo = null;
	protected ?\DateTime $invoiceDate = null;
	protected ?\DateTime $paymentDate = null;
	protected string $currency = '';
	protected ?int $fxRateMilli = null;
	protected int $totalMinor = 0;
	protected int $taxMinor = 0;
	protected ?string $purpose = null;
	protected ?int $fileId = null;
	protected string $kind = '';
	protected string $state = '';
	protected bool $dupFlag = false;
	protected ?string $externalRef = null;
	protected ?string $salaryPeriod = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('vendor', 'string');
		$this->addType('invoiceNo', 'string');
		$this->addType('invoiceDate', 'datetime');
		$this->addType('paymentDate', 'datetime');
		$this->addType('currency', 'string');
		$this->addType('fxRateMilli', 'integer');
		$this->addType('totalMinor', 'integer');
		$this->addType('taxMinor', 'integer');
		$this->addType('purpose', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('state', 'string');
		$this->addType('dupFlag', 'boolean');
		$this->addType('externalRef', 'string');
		$this->addType('salaryPeriod', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
