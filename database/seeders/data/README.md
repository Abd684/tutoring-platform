# Syrian registration locations

`syria-locations.json` contains 14 governorates, 62 administrative districts (ADM2), and 2,105 named school entries. Import into the configured database with:

```sh
php artisan db:seed --class=GovernorateSeeder --force
```

The seeder only creates missing records, keeps existing IDs and student relationships, and can be rerun. It replaces the former placeholder-school seeder; it does not remove existing placeholder records.

## Sources and attribution

- Governorates and districts: [OCHA Common Operational Dataset, Syrian Arab Republic administrative boundaries](https://data.humdata.org/dataset/cod-ab-syr). Arabic ADM1/ADM2 names and parent P-codes were extracted from the published GeoJSON package. The records specify validity from 2020-12-17; these are the reference divisions in that dataset, not a claim of live administrative changes.
- Schools: [HOT / HDX Education Facilities of Syria](https://data.humdata.org/dataset/hotosm_syr_education_facilities), OpenStreetMap snapshot 2026-09-06, retrieved 2026-10-01. © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright), Humanitarian OpenStreetMap Team. The school-derived data is shared under [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/).

Only records tagged `amenity=school` with a meaningful supplied name and a matching ADM2 P-code are included. Arabic names are preferred, followed by the supplied original name, then English. Universities, unnamed facilities, and generic labels are excluded. Source ADM1 and ADM2 codes are checked for consistency.

Repeated OSM node/way names in the same district and subdistrict are combined, keeping all source IDs. Same-named schools in different subdistricts receive an Arabic locality qualifier to distinguish registration choices. This process may combine distinct same-named campuses within one subdistrict; the dataset is a registration catalogue, not a verified institution registry.

The school data is incomplete and does not establish whether each school is currently open. No named schools were available in the selected source for القصير، فيق، السفيرة، جرابلس، يبرود. Those districts are imported without invented schools.
