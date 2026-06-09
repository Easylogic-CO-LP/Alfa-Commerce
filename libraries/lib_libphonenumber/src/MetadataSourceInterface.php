<?php

declare(strict_types=1);

namespace libphonenumber;

interface MetadataSourceInterface
{
    /**
     * Gets phone metadata for a region.
     * @since  1.0.0
     */
    public function getMetadataForRegion(string $regionCode): PhoneMetadata;

    /**
     * Gets phone metadata for a non-geographical region.
     * @since  1.0.0
     */
    public function getMetadataForNonGeographicalRegion(int $countryCallingCode): PhoneMetadata;
}
