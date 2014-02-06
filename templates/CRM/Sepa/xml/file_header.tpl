<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-intance">
  <CstmrDrctDbtInitn>
    <GrpHdr>
      <MsgId>{$file.reference}</MsgId>
      <CreDtTm>{$file.created_date|crmDate:"%Y-%m-%dT%H:%i:42"}</CreDtTm>
      <NbOfTxs>{$nbtransactions}</NbOfTxs>
      <CtrlSum>{$total}</CtrlSum>
      <InitgPty>
        <Nm>{$creditor.name}</Nm>
      </InitgPty>
    </GrpHdr>
